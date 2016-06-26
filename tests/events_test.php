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
 * Tests for digestforum events.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for digestforum events.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_digestforum_events_testcase extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp() {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();

        $this->resetAfterTest();
    }

    public function tearDown() {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
    }

    /**
     * Ensure course_searched event validates that searchterm is set.
     */
    public function test_course_searched_searchterm_validation() {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = context_course::instance($course->id);
        $params = array(
            'context' => $coursectx,
        );

        $this->setExpectedException('coding_exception', 'The \'searchterm\' value must be set in other.');
        \mod_digestforum\event\course_searched::create($params);
    }

    /**
     * Ensure course_searched event validates that context is the correct level.
     */
    public function test_course_searched_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);
        $params = array(
            'context' => $context,
            'other' => array('searchterm' => 'testing'),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_COURSE.');
        \mod_digestforum\event\course_searched::create($params);
    }

    /**
     * Test course_searched event.
     */
    public function test_course_searched() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $coursectx = context_course::instance($course->id);
        $searchterm = 'testing123';

        $params = array(
            'context' => $coursectx,
            'other' => array('searchterm' => $searchterm),
        );

        // Create event.
        $event = \mod_digestforum\event\course_searched::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

         // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\course_searched', $event);
        $this->assertEquals($coursectx, $event->get_context());
        $expected = array($course->id, 'digestforum', 'search', "search.php?id={$course->id}&amp;search={$searchterm}", $searchterm);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_created event validates that digestforumid is set.
     */
    public function test_discussion_created_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_created::create($params);
    }

    /**
     * Ensure discussion_created event validates that the context is the correct level.
     */
    public function test_discussion_created_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_created::create($params);
    }

    /**
     * Test discussion_created event.
     */
    public function test_discussion_created() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $discussion->id,
            'other' => array('digestforumid' => $digestforum->id),
        );

        // Create the event.
        $event = \mod_digestforum\event\discussion_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Check that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'add discussion', "discuss.php?d={$discussion->id}", $discussion->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_updated event validates that digestforumid is set.
     */
    public function test_discussion_updated_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_updated::create($params);
    }

    /**
     * Ensure discussion_created event validates that the context is the correct level.
     */
    public function test_discussion_updated_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_updated::create($params);
    }

    /**
     * Test discussion_created event.
     */
    public function test_discussion_updated() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $discussion->id,
            'other' => array('digestforumid' => $digestforum->id),
        );

        // Create the event.
        $event = \mod_digestforum\event\discussion_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Check that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_deleted event validates that digestforumid is set.
     */
    public function test_discussion_deleted_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_deleted::create($params);
    }

    /**
     * Ensure discussion_deleted event validates that context is of the correct level.
     */
    public function test_discussion_deleted_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_deleted::create($params);
    }

    /**
     * Test discussion_deleted event.
     */
    public function test_discussion_deleted() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $discussion->id,
            'other' => array('digestforumid' => $digestforum->id),
        );

        $event = \mod_digestforum\event\discussion_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'delete discussion', "view.php?id={$digestforum->cmid}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_moved event validates that fromdigestforumid is set.
     */
    public function test_discussion_moved_fromdigestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $todigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $context = context_module::instance($todigestforum->cmid);

        $params = array(
            'context' => $context,
            'other' => array('todigestforumid' => $todigestforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'fromdigestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_moved::create($params);
    }

    /**
     * Ensure discussion_moved event validates that todigestforumid is set.
     */
    public function test_discussion_moved_todigestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $fromdigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $todigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($todigestforum->cmid);

        $params = array(
            'context' => $context,
            'other' => array('fromdigestforumid' => $fromdigestforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'todigestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_moved::create($params);
    }

    /**
     * Ensure discussion_moved event validates that the context level is correct.
     */
    public function test_discussion_moved_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $fromdigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $todigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $fromdigestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $discussion->id,
            'other' => array('fromdigestforumid' => $fromdigestforum->id, 'todigestforumid' => $todigestforum->id)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_moved::create($params);
    }

    /**
     * Test discussion_moved event.
     */
    public function test_discussion_moved() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $fromdigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $todigestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $fromdigestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $context = context_module::instance($todigestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $discussion->id,
            'other' => array('fromdigestforumid' => $fromdigestforum->id, 'todigestforumid' => $todigestforum->id)
        );

        $event = \mod_digestforum\event\discussion_moved::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_moved', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'move discussion', "discuss.php?d={$discussion->id}",
            $discussion->id, $todigestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }


    /**
     * Ensure discussion_viewed event validates that the contextlevel is correct.
     */
    public function test_discussion_viewed_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $discussion->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_viewed::create($params);
    }

    /**
     * Test discussion_viewed event.
     */
    public function test_discussion_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $discussion->id,
        );

        $event = \mod_digestforum\event\discussion_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'view discussion', "discuss.php?d={$discussion->id}",
            $discussion->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure course_module_viewed event validates that the contextlevel is correct.
     */
    public function test_course_module_viewed_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $digestforum->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\course_module_viewed::create($params);
    }

    /**
     * Test the course_module_viewed event.
     */
    public function test_course_module_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $digestforum->id,
        );

        $event = \mod_digestforum\event\course_module_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\course_module_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'view digestforum', "view.php?f={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure subscription_created event validates that the digestforumid is set.
     */
    public function test_subscription_created_digestforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\subscription_created::create($params);
    }

    /**
     * Ensure subscription_created event validates that the relateduserid is set.
     */
    public function test_subscription_created_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $digestforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_digestforum\event\subscription_created::create($params);
    }

    /**
     * Ensure subscription_created event validates that the contextlevel is correct.
     */
    public function test_subscription_created_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\subscription_created::create($params);
    }

    /**
     * Test the subscription_created event.
     */
    public function test_subscription_created() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();
        $context = context_module::instance($digestforum->cmid);

        // Add a subscription.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $subscription = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_subscription($record);

        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $event = \mod_digestforum\event\subscription_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'subscribe', "view.php?f={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/subscribers.php', array('id' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure subscription_deleted event validates that the digestforumid is set.
     */
    public function test_subscription_deleted_digestforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\subscription_deleted::create($params);
    }

    /**
     * Ensure subscription_deleted event validates that the relateduserid is set.
     */
    public function test_subscription_deleted_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $digestforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_digestforum\event\subscription_deleted::create($params);
    }

    /**
     * Ensure subscription_deleted event validates that the contextlevel is correct.
     */
    public function test_subscription_deleted_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\subscription_deleted::create($params);
    }

    /**
     * Test the subscription_deleted event.
     */
    public function test_subscription_deleted() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();
        $context = context_module::instance($digestforum->cmid);

        // Add a subscription.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $subscription = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_subscription($record);

        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $event = \mod_digestforum\event\subscription_deleted::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'unsubscribe', "view.php?f={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/subscribers.php', array('id' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure readtracking_enabled event validates that the digestforumid is set.
     */
    public function test_readtracking_enabled_digestforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\readtracking_enabled::create($params);
    }

    /**
     * Ensure readtracking_enabled event validates that the relateduserid is set.
     */
    public function test_readtracking_enabled_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $digestforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_digestforum\event\readtracking_enabled::create($params);
    }

    /**
     * Ensure readtracking_enabled event validates that the contextlevel is correct.
     */
    public function test_readtracking_enabled_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\readtracking_enabled::create($params);
    }

    /**
     * Test the readtracking_enabled event.
     */
    public function test_readtracking_enabled() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $event = \mod_digestforum\event\readtracking_enabled::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\readtracking_enabled', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'start tracking', "view.php?f={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure readtracking_disabled event validates that the digestforumid is set.
     */
    public function test_readtracking_disabled_digestforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Ensure readtracking_disabled event validates that the relateduserid is set.
     */
    public function test_readtracking_disabled_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $digestforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_digestforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Ensure readtracking_disabled event validates that the contextlevel is correct
     */
    public function test_readtracking_disabled_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Test the readtracking_disabled event.
     */
    public function test_readtracking_disabled() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $event = \mod_digestforum\event\readtracking_disabled::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\readtracking_disabled', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'stop tracking', "view.php?f={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure subscribers_viewed event validates that the digestforumid is set.
     */
    public function test_subscribers_viewed_digestforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\subscribers_viewed::create($params);
    }

    /**
     *  Ensure subscribers_viewed event validates that the contextlevel is correct.
     */
    public function test_subscribers_viewed_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_system::instance(),
            'other' => array('digestforumid' => $digestforum->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\subscribers_viewed::create($params);
    }

    /**
     *  Test the subscribers_viewed event.
     */
    public function test_subscribers_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'other' => array('digestforumid' => $digestforum->id),
        );

        $event = \mod_digestforum\event\subscribers_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscribers_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'view subscribers', "subscribers.php?id={$digestforum->id}", $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure user_report_viewed event validates that the reportmode is set.
     */
    public function test_user_report_viewed_reportmode_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $params = array(
            'context' => context_course::instance($course->id),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'reportmode\' value must be set in other.');
        \mod_digestforum\event\user_report_viewed::create($params);
    }

    /**
     *  Ensure user_report_viewed event validates that the contextlevel is correct.
     */
    public function test_user_report_viewed_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'other' => array('reportmode' => 'posts'),
            'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception',
                'Context level must be either CONTEXT_SYSTEM, CONTEXT_COURSE or CONTEXT_USER.');
        \mod_digestforum\event\user_report_viewed::create($params);
    }

    /**
     *  Ensure user_report_viewed event validates that the relateduserid is set.
     */
    public function test_user_report_viewed_relateduserid_validation() {

        $params = array(
            'context' => context_system::instance(),
            'other' => array('reportmode' => 'posts'),
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_digestforum\event\user_report_viewed::create($params);
    }

    /**
     * Test the user_report_viewed event.
     */
    public function test_user_report_viewed() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $params = array(
            'context' => $context,
            'relateduserid' => $user->id,
            'other' => array('reportmode' => 'discussions'),
        );

        $event = \mod_digestforum\event\user_report_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\user_report_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'user report',
            "user.php?id={$user->id}&amp;mode=discussions&amp;course={$course->id}", $user->id);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_created event validates that the postid is set.
     */
    public function test_post_created_postid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'other' => array('digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type, 'discussionid' => $discussion->id)
        );

        \mod_digestforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the discussionid is set.
     */
    public function test_post_created_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_digestforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the digestforumid is set.
     */
    public function test_post_created_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the digestforumtype is set.
     */
    public function test_post_created_digestforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumtype\' value must be set in other.');
        \mod_digestforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the contextlevel is correct.
     */
    public function test_post_created_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_digestforum\event\post_created::create($params);
    }

    /**
     * Test the post_created event.
     */
    public function test_post_created() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $event = \mod_digestforum\event\post_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'add post', "discuss.php?d={$discussion->id}#p{$post->id}",
            $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test the post_created event for a single discussion digestforum.
     */
    public function test_post_created_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $event = \mod_digestforum\event\post_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'add post', "view.php?f={$digestforum->id}#p{$post->id}",
            $digestforum->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_deleted event validates that the postid is set.
     */
    public function test_post_deleted_postid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'other' => array('digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type, 'discussionid' => $discussion->id)
        );

        \mod_digestforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the discussionid is set.
     */
    public function test_post_deleted_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_digestforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the digestforumid is set.
     */
    public function test_post_deleted_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the digestforumtype is set.
     */
    public function test_post_deleted_digestforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumtype\' value must be set in other.');
        \mod_digestforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the contextlevel is correct.
     */
    public function test_post_deleted_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_digestforum\event\post_deleted::create($params);
    }

    /**
     * Test post_deleted event.
     */
    public function test_post_deleted() {
        global $DB;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // When creating a discussion we also create a post, so get the post.
        $discussionpost = $DB->get_records('digestforum_posts');
        // Will only be one here.
        $discussionpost = reset($discussionpost);

        // Add a few posts.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $posts = array();
        $posts[$discussionpost->id] = $discussionpost;
        for ($i = 0; $i < 3; $i++) {
            $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);
            $posts[$post->id] = $post;
        }

        // Delete the last post and capture the event.
        $lastpost = end($posts);
        $sink = $this->redirectEvents();
        digestforum_delete_post($lastpost, true, $course, $cm, $digestforum);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Check that the events contain the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_deleted', $event);
        $this->assertEquals(context_module::instance($digestforum->cmid), $event->get_context());
        $expected = array($course->id, 'digestforum', 'delete post', "discuss.php?d={$discussion->id}", $lastpost->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_name());

        // Delete the whole discussion and capture the events.
        $sink = $this->redirectEvents();
        digestforum_delete_discussion($discussion, true, $course, $cm, $digestforum);
        $events = $sink->get_events();
        // We will have 3 events. One for the discussion (creating a discussion creates a post), and two for the posts.
        $this->assertCount(3, $events);

        // Loop through the events and check they are valid.
        foreach ($events as $event) {
            $post = $posts[$event->objectid];

            // Check that the event contains the expected values.
            $this->assertInstanceOf('\mod_digestforum\event\post_deleted', $event);
            $this->assertEquals(context_module::instance($digestforum->cmid), $event->get_context());
            $expected = array($course->id, 'digestforum', 'delete post', "discuss.php?d={$discussion->id}", $post->id, $digestforum->cmid);
            $this->assertEventLegacyLogData($expected, $event);
            $url = new \moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id));
            $this->assertEquals($url, $event->get_url());
            $this->assertEventContextNotUsed($event);
            $this->assertNotEmpty($event->get_name());
        }
    }

    /**
     * Test post_deleted event for a single discussion digestforum.
     */
    public function test_post_deleted_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $event = \mod_digestforum\event\post_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'delete post', "view.php?f={$digestforum->id}", $post->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_updated event validates that the discussionid is set.
     */
    public function test_post_updated_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_digestforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the digestforumid is set.
     */
    public function test_post_updated_digestforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the digestforumtype is set.
     */
    public function test_post_updated_digestforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'digestforumtype\' value must be set in other.');
        \mod_digestforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the contextlevel is correct.
     */
    public function test_post_updated_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $params = array(
            'context' => context_system::instance(),
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_digestforum\event\post_updated::create($params);
    }

    /**
     * Test post_updated event.
     */
    public function test_post_updated() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $event = \mod_digestforum\event\post_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'update post', "discuss.php?d={$discussion->id}#p{$post->id}",
            $post->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test post_updated event.
     */
    public function test_post_updated_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array('discussionid' => $discussion->id, 'digestforumid' => $digestforum->id, 'digestforumtype' => $digestforum->type)
        );

        $event = \mod_digestforum\event\post_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\post_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'digestforum', 'update post', "view.php?f={$digestforum->id}#p{$post->id}",
            $post->id, $digestforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $url->set_anchor('p'.$post->id);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test discussion_subscription_created event.
     */
    public function test_discussion_subscription_created() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();

        // Trigger the event by subscribing the user to the digestforum discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_created', $event);


        $cm = get_coursemodule_from_instance('digestforum', $discussion->digestforum);
        $context = \context_module::instance($cm->id);
        $this->assertEquals($context, $event->get_context());

        $url = new \moodle_url('/mod/digestforum/subscribe.php', array(
            'id' => $digestforum->id,
            'd' => $discussion->id
        ));

        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test validation of discussion_subscription_created event.
     */
    public function test_discussion_subscription_created_validation() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        $event = \mod_digestforum\event\discussion_subscription_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
    }

    /**
     * Test contextlevel validation of discussion_subscription_created event.
     */
    public function test_discussion_subscription_created_validation_contextlevel() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => \context_course::instance($course->id),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        // Without an invalid context.
        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_subscription_created::create($params);
    }

    /**
     * Test discussion validation of discussion_subscription_created event.
     */
    public function test_discussion_subscription_created_validation_discussion() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        // Without the discussion.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'discussion' value must be set in other.");
        \mod_digestforum\event\discussion_subscription_created::create($params);
    }

    /**
     * Test digestforumid validation of discussion_subscription_created event.
     */
    public function test_discussion_subscription_created_validation_digestforumid() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        // Without the digestforumid.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'discussion' => $discussion->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'digestforumid' value must be set in other.");
        \mod_digestforum\event\discussion_subscription_created::create($params);
    }

    /**
     * Test relateduserid validation of discussion_subscription_created event.
     */
    public function test_discussion_subscription_created_validation_relateduserid() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        // Without the relateduserid.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'relateduserid' must be set.");
        \mod_digestforum\event\discussion_subscription_created::create($params);
    }

    /**
     * Test discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();

        // Trigger the event by unsubscribing the user to the digestforum discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_deleted', $event);


        $cm = get_coursemodule_from_instance('digestforum', $discussion->digestforum);
        $context = \context_module::instance($cm->id);
        $this->assertEquals($context, $event->get_context());

        $url = new \moodle_url('/mod/digestforum/subscribe.php', array(
            'id' => $digestforum->id,
            'd' => $discussion->id
        ));

        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test validation of discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted_validation() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = \mod_digestforum\subscriptions::DFORUM_DISCUSSION_UNSUBSCRIBED;

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        $event = \mod_digestforum\event\discussion_subscription_deleted::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Without an invalid context.
        $params['context'] = \context_course::instance($course->id);
        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_deleted::create($params);

        // Without the discussion.
        unset($params['discussion']);
        $this->setExpectedException('coding_exception', 'The \'discussion\' value must be set in other.');
        \mod_digestforum\event\discussion_deleted::create($params);

        // Without the digestforumid.
        unset($params['digestforumid']);
        $this->setExpectedException('coding_exception', 'The \'digestforumid\' value must be set in other.');
        \mod_digestforum\event\discussion_deleted::create($params);

        // Without the relateduserid.
        unset($params['relateduserid']);
        $this->setExpectedException('coding_exception', 'The \'relateduserid\' value must be set in other.');
        \mod_digestforum\event\discussion_deleted::create($params);
    }

    /**
     * Test contextlevel validation of discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted_validation_contextlevel() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        $params = array(
            'context' => \context_course::instance($course->id),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        // Without an invalid context.
        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_digestforum\event\discussion_subscription_deleted::create($params);
    }

    /**
     * Test discussion validation of discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted_validation_discussion() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        // Without the discussion.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'discussion' value must be set in other.");
        \mod_digestforum\event\discussion_subscription_deleted::create($params);
    }

    /**
     * Test digestforumid validation of discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted_validation_digestforumid() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        // Without the digestforumid.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'relateduserid' => $user->id,
            'other' => array(
                'discussion' => $discussion->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'digestforumid' value must be set in other.");
        \mod_digestforum\event\discussion_subscription_deleted::create($params);
    }

    /**
     * Test relateduserid validation of discussion_subscription_deleted event.
     */
    public function test_discussion_subscription_deleted_validation_relateduserid() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // The user is not subscribed to the digestforum. Insert a new discussion subscription.
        $subscription = new \stdClass();
        $subscription->userid  = $user->id;
        $subscription->digestforum = $digestforum->id;
        $subscription->discussion = $discussion->id;
        $subscription->preference = time();

        $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);

        $context = context_module::instance($digestforum->cmid);

        // Without the relateduserid.
        $params = array(
            'context' => context_module::instance($digestforum->cmid),
            'objectid' => $subscription->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
                'discussion' => $discussion->id,
            )
        );

        $this->setExpectedException('coding_exception', "The 'relateduserid' must be set.");
        \mod_digestforum\event\discussion_subscription_deleted::create($params);
    }

    /**
     * Test that the correct context is used in the events when subscribing
     * users.
     */
    public function test_digestforum_subscription_page_context_valid() {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $quiz = $this->getDataGenerator()->create_module('quiz', $options);

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Set up the default page event to use this digestforum.
        $PAGE = new moodle_page();
        $cm = get_coursemodule_from_instance('digestforum', $discussion->digestforum);
        $context = \context_module::instance($cm->id);
        $PAGE->set_context($context);
        $PAGE->set_cm($cm, $course, $digestforum);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();

        // Trigger the event by subscribing the user to the digestforum.
        \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user to the digestforum.
        \mod_digestforum\subscriptions::unsubscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by subscribing the user to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

        // Now try with the context for a different module (quiz).
        $PAGE = new moodle_page();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $quizcontext = \context_module::instance($cm->id);
        $PAGE->set_context($quizcontext);
        $PAGE->set_cm($cm, $course, $quiz);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();

        // Trigger the event by subscribing the user to the digestforum.
        \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user to the digestforum.
        \mod_digestforum\subscriptions::unsubscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by subscribing the user to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

        // Now try with the course context - the module context should still be used.
        $PAGE = new moodle_page();
        $coursecontext = \context_course::instance($course->id);
        $PAGE->set_context($coursecontext);

        // Trigger the event by subscribing the user to the digestforum.
        \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user to the digestforum.
        \mod_digestforum\subscriptions::unsubscribe_user($user->id, $digestforum);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by subscribing the user to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_created', $event);
        $this->assertEquals($context, $event->get_context());

        // Trigger the event by unsubscribing the user from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);

        $events = $sink->get_events();
        $sink->clear();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\discussion_subscription_deleted', $event);
        $this->assertEquals($context, $event->get_context());

    }

    /**
     * Test mod_digestforum_observer methods.
     */
    public function test_observers() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/digestforum/lib.php');

        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');

        $course = $this->getDataGenerator()->create_course();
        $trackedrecord = array('course' => $course->id, 'type' => 'general', 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $untrackedrecord = array('course' => $course->id, 'type' => 'general');
        $trackeddigestforum = $this->getDataGenerator()->create_module('digestforum', $trackedrecord);
        $untrackeddigestforum = $this->getDataGenerator()->create_module('digestforum', $untrackedrecord);

        // Used functions don't require these settings; adding
        // them just in case there are APIs changes in future.
        $user = $this->getDataGenerator()->create_user(array(
            'maildigest' => 1,
            'trackdigestforums' => 1
        ));

        $manplugin = enrol_get_plugin('manual');
        $manualenrol = $DB->get_record('enrol', array('courseid' => $course->id, 'enrol' => 'manual'));
        $student = $DB->get_record('role', array('shortname' => 'student'));

        // The role_assign observer does it's job adding the digestforum_subscriptions record.
        $manplugin->enrol_user($manualenrol, $user->id, $student->id);

        // They are not required, but in a real environment they are supposed to be required;
        // adding them just in case there are APIs changes in future.
        set_config('digestforum_trackingtype', 1);
        set_config('digestforum_trackreadposts', 1);

        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $trackeddigestforum->id;
        $record['userid'] = $user->id;
        $discussion = $digestforumgen->create_discussion($record);

        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $digestforumgen->create_post($record);

        digestforum_tp_add_read_record($user->id, $post->id);
        digestforum_set_user_maildigest($trackeddigestforum, 2, $user);
        digestforum_tp_stop_tracking($untrackeddigestforum->id, $user->id);

        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions'));
        $this->assertEquals(1, $DB->count_records('digestforum_digests'));
        $this->assertEquals(1, $DB->count_records('digestforum_track_prefs'));
        $this->assertEquals(1, $DB->count_records('digestforum_read'));

        // The course_module_created observer does it's job adding a subscription.
        $digestforumrecord = array('course' => $course->id, 'type' => 'general', 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $extradigestforum = $this->getDataGenerator()->create_module('digestforum', $digestforumrecord);
        $this->assertEquals(2, $DB->count_records('digestforum_subscriptions'));

        $manplugin->unenrol_user($manualenrol, $user->id);

        $this->assertEquals(0, $DB->count_records('digestforum_digests'));
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions'));
        $this->assertEquals(0, $DB->count_records('digestforum_track_prefs'));
        $this->assertEquals(0, $DB->count_records('digestforum_read'));
    }

}
