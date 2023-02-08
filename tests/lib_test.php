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
require_once($CFG->dirroot . '/mod/digestforum/locallib.php');
require_once($CFG->dirroot . '/rating/lib.php');

class mod_digestforum_lib_testcase extends advanced_testcase {

    public function setUp() {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
    }

    public function tearDown() {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
    }

    public function test_digestforum_trigger_content_uploaded_event() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $context = context_module::instance($digestforum->cmid);

        $this->setUser($user->id);
        $fakepost = (object) array('id' => 123, 'message' => 'Yay!', 'discussion' => 100);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        $fs = get_file_storage();
        $dummy = (object) array(
            'contextid' => $context->id,
            'component' => 'mod_digestforum',
            'filearea' => 'attachment',
            'itemid' => $fakepost->id,
            'filepath' => '/',
            'filename' => 'myassignmnent.pdf'
        );
        $fi = $fs->create_file_from_string($dummy, 'Content of ' . $dummy->filename);

        $data = new stdClass();
        $sink = $this->redirectEvents();
        digestforum_trigger_content_uploaded_event($fakepost, $cm, 'some triggered from value');
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf('\mod_digestforum\event\assessable_uploaded', $event);
        $this->assertEquals($context->id, $event->contextid);
        $this->assertEquals($fakepost->id, $event->objectid);
        $this->assertEquals($fakepost->message, $event->other['content']);
        $this->assertEquals($fakepost->discussion, $event->other['discussionid']);
        $this->assertCount(1, $event->other['pathnamehashes']);
        $this->assertEquals($fi->get_pathnamehash(), $event->other['pathnamehashes'][0]);
        $expected = new stdClass();
        $expected->modulename = 'digestforum';
        $expected->name = 'some triggered from value';
        $expected->cmid = $digestforum->cmid;
        $expected->itemid = $fakepost->id;
        $expected->courseid = $course->id;
        $expected->userid = $user->id;
        $expected->content = $fakepost->message;
        $expected->pathnamehashes = array($fi->get_pathnamehash());
        $this->assertEventLegacyData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_digestforum_get_courses_user_posted_in() {
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();

        // Create 3 digestforums, one in each course.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', $record);

        $record = new stdClass();
        $record->course = $course2->id;
        $digestforum2 = $this->getDataGenerator()->create_module('digestforum', $record);

        $record = new stdClass();
        $record->course = $course3->id;
        $digestforum3 = $this->getDataGenerator()->create_module('digestforum', $record);

        // Add a second digestforum in course 1.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum4 = $this->getDataGenerator()->create_module('digestforum', $record);

        // Add discussions to course 1 started by user1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum4->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add discussions to course2 started by user1.
        $record = new stdClass();
        $record->course = $course2->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum2->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add discussions to course 3 started by user2.
        $record = new stdClass();
        $record->course = $course3->id;
        $record->userid = $user2->id;
        $record->digestforum = $digestforum3->id;
        $discussion3 = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add post to course 3 by user1.
        $record = new stdClass();
        $record->course = $course3->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum3->id;
        $record->discussion = $discussion3->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // User 3 hasn't posted anything, so shouldn't get any results.
        $user3courses = digestforum_get_courses_user_posted_in($user3);
        $this->assertEmpty($user3courses);

        // User 2 has only posted in course3.
        $user2courses = digestforum_get_courses_user_posted_in($user2);
        $this->assertCount(1, $user2courses);
        $user2course = array_shift($user2courses);
        $this->assertEquals($course3->id, $user2course->id);
        $this->assertEquals($course3->shortname, $user2course->shortname);

        // User 1 has posted in all 3 courses.
        $user1courses = digestforum_get_courses_user_posted_in($user1);
        $this->assertCount(3, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id, $course3->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname,
                $course3->shortname));

        }

        // User 1 has only started a discussion in course 1 and 2 though.
        $user1courses = digestforum_get_courses_user_posted_in($user1, true);
        $this->assertCount(2, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname));
        }
    }

    /**
     * Test the logic in the digestforum_tp_can_track_digestforums() function.
     */
    public function test_digestforum_tp_can_track_digestforums() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OFF); // Off.
        $digestforumoff = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_FORCED); // On.
        $digestforumforce = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OPTIONAL); // Optional.
        $digestforumoptional = $this->getDataGenerator()->create_module('digestforum', $options);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        // User on, digestforum off, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, digestforum on, should be on.
        $result = digestforum_tp_can_track_digestforums($digestforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, digestforum optional, should be on.
        $result = digestforum_tp_can_track_digestforums($digestforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, digestforum off, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum force, should be on.
        $result = digestforum_tp_can_track_digestforums($digestforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, digestforum optional, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        // User on, digestforum off, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, digestforum on, should be on.
        $result = digestforum_tp_can_track_digestforums($digestforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, digestforum optional, should be on.
        $result = digestforum_tp_can_track_digestforums($digestforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, digestforum off, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum force, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum optional, should be off.
        $result = digestforum_tp_can_track_digestforums($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);

    }

    /**
     * Test the logic in the test_digestforum_tp_is_tracked() function.
     */
    public function test_digestforum_tp_is_tracked() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OFF); // Off.
        $digestforumoff = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_FORCED); // On.
        $digestforumforce = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OPTIONAL); // Optional.
        $digestforumoptional = $this->getDataGenerator()->create_module('digestforum', $options);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        // User on, digestforum off, should be off.
        $result = digestforum_tp_is_tracked($digestforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, digestforum optional, should be on.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, digestforum off, should be off.
        $result = digestforum_tp_is_tracked($digestforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, digestforum optional, should be off.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        // User on, digestforum off, should be off.
        $result = digestforum_tp_is_tracked($digestforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, digestforum optional, should be on.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, digestforum off, should be off.
        $result = digestforum_tp_is_tracked($digestforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum force, should be off.
        $result = digestforum_tp_is_tracked($digestforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, digestforum optional, should be off.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Stop tracking so we can test again.
        digestforum_tp_stop_tracking($digestforumforce->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumforce->id, $useroff->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        // User on, preference off, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, preference off, digestforum optional, should be on.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, preference off, digestforum optional, should be off.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        // User on, preference off, digestforum force, should be on.
        $result = digestforum_tp_is_tracked($digestforumforce, $useron);
        $this->assertEquals(false, $result);

        // User on, preference off, digestforum optional, should be on.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, digestforum force, should be off.
        $result = digestforum_tp_is_tracked($digestforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, preference off, digestforum optional, should be off.
        $result = digestforum_tp_is_tracked($digestforumoptional, $useroff);
        $this->assertEquals(false, $result);
    }

    /**
     * Test the logic in the digestforum_tp_get_course_unread_posts() function.
     */
    public function test_digestforum_tp_get_course_unread_posts() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OFF); // Off.
        $digestforumoff = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_FORCED); // On.
        $digestforumforce = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OPTIONAL); // Optional.
        $digestforumoptional = $this->getDataGenerator()->create_module('digestforum', $options);

        // Add discussions to the tracking off digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->digestforum = $digestforumoff->id;
        $discussionoff = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add discussions to the tracking forced digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->digestforum = $digestforumforce->id;
        $discussionforce = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Add post to the tracking forced discussion.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useroff->id;
        $record->digestforum = $digestforumforce->id;
        $record->discussion = $discussionforce->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Add discussions to the tracking optional digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->digestforum = $digestforumoptional->id;
        $discussionoptional = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        $result = digestforum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
        $this->assertEquals(2, $result[$digestforumforce->id]->unread);
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));
        $this->assertEquals(1, $result[$digestforumoptional->id]->unread);

        $result = digestforum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
        $this->assertEquals(2, $result[$digestforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        $result = digestforum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
        $this->assertEquals(2, $result[$digestforumforce->id]->unread);
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));
        $this->assertEquals(1, $result[$digestforumoptional->id]->unread);

        $result = digestforum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(false, isset($result[$digestforumforce->id]));
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));

        // Stop tracking so we can test again.
        digestforum_tp_stop_tracking($digestforumforce->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumforce->id, $useroff->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        $result = digestforum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
        $this->assertEquals(2, $result[$digestforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));

        $result = digestforum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
        $this->assertEquals(2, $result[$digestforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        $result = digestforum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(false, isset($result[$digestforumforce->id]));
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));

        $result = digestforum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$digestforumoff->id]));
        $this->assertEquals(false, isset($result[$digestforumforce->id]));
        $this->assertEquals(false, isset($result[$digestforumoptional->id]));
    }

    /**
     * Test the logic in the test_digestforum_tp_get_untracked_digestforums() function.
     */
    public function test_digestforum_tp_get_untracked_digestforums() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OFF); // Off.
        $digestforumoff = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_FORCED); // On.
        $digestforumforce = $this->getDataGenerator()->create_module('digestforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => DFORUM_TRACKING_OPTIONAL); // Optional.
        $digestforumoptional = $this->getDataGenerator()->create_module('digestforum', $options);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        // On user with force on.
        $result = digestforum_tp_get_untracked_digestforums($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));

        // Off user with force on.
        $result = digestforum_tp_get_untracked_digestforums($useroff->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        // On user with force off.
        $result = digestforum_tp_get_untracked_digestforums($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));

        // Off user with force off.
        $result = digestforum_tp_get_untracked_digestforums($useroff->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));

        // Stop tracking so we can test again.
        digestforum_tp_stop_tracking($digestforumforce->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useron->id);
        digestforum_tp_stop_tracking($digestforumforce->id, $useroff->id);
        digestforum_tp_stop_tracking($digestforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->digestforum_allowforcedreadtracking = 1;

        // On user with force on.
        $result = digestforum_tp_get_untracked_digestforums($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));

        // Off user with force on.
        $result = digestforum_tp_get_untracked_digestforums($useroff->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));

        // Don't allow force.
        $CFG->digestforum_allowforcedreadtracking = 0;

        // On user with force off.
        $result = digestforum_tp_get_untracked_digestforums($useron->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));

        // Off user with force off.
        $result = digestforum_tp_get_untracked_digestforums($useroff->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$digestforumoff->id]));
        $this->assertEquals(true, isset($result[$digestforumoptional->id]));
        $this->assertEquals(true, isset($result[$digestforumforce->id]));
    }

    /**
     * Test subscription using automatic subscription on create.
     */
    public function test_digestforum_auto_subscribe_on_create() {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE); // Automatic Subscription.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $result = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($result));
        foreach ($users as $user) {
            $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        }
    }

    /**
     * Test subscription using forced subscription on create.
     */
    public function test_digestforum_forced_subscribe_on_create() {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_FORCESUBSCRIBE); // Forced subscription.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $result = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($result));
        foreach ($users as $user) {
            $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        }
    }

    /**
     * Test subscription using optional subscription on create.
     */
    public function test_digestforum_optional_subscribe_on_create() {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE); // Subscription optional.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $result = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        // No subscriptions by default.
        $this->assertEquals(0, count($result));
        foreach ($users as $user) {
            $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        }
    }

    /**
     * Test subscription using disallow subscription on create.
     */
    public function test_digestforum_disallow_subscribe_on_create() {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE); // Subscription prevented.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $result = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        // No subscriptions by default.
        $this->assertEquals(0, count($result));
        foreach ($users as $user) {
            $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        }
    }

    /**
     * Test that context fetching returns the appropriate context.
     */
    public function test_digestforum_get_context() {
        global $DB, $PAGE;

        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $digestforumcm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $digestforumcontext = \context_module::instance($digestforumcm->id);

        // First check that specifying the context results in the correct context being returned.
        // Do this before we set up the page object and we should return from the coursemodule record.
        // There should be no DB queries here because the context type was correct.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id, $digestforumcontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // And a context which is not the correct type.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Now do not specify a context at all.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Set up the default page event to use the digestforum.
        $PAGE = new moodle_page();
        $PAGE->set_context($digestforumcontext);
        $PAGE->set_cm($digestforumcm, $course, $digestforum);

        // Now specify a context which is not a context_module.
        // There should be no DB queries here because we use the PAGE.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // Now do not specify a context at all.
        // There should be no DB queries here because we use the PAGE.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // Now specify the page context of the course instead..
        $PAGE = new moodle_page();
        $PAGE->set_context($coursecontext);

        // Now specify a context which is not a context_module.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Now do not specify a context at all.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = digestforum_get_context($digestforum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($digestforumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);
    }

    /**
     * Test getting the neighbour threads of a discussion.
     */
    public function test_digestforum_get_neighbours() {
        global $CFG, $DB;
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = context_module::instance($cm->id);

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->timemodified = time();
        $disc1 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc2 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc3 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc4 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc5 = $digestforumgen->create_discussion($record);

        // Getting the neighbours.
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc1, $digestforum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc3, $digestforum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc4->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc4, $digestforum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc5->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc5, $digestforum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Post in some discussions. We manually update the discussion record because
        // the data generator plays with timemodified in a way that would break this test.
        $record->timemodified++;
        $disc1->timemodified = $record->timemodified;
        $DB->update_record('digestforum_discussions', $disc1);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc5, $digestforum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEquals($disc1->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc1, $digestforum);
        $this->assertEquals($disc5->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // After some discussions were created.
        $record->timemodified++;
        $disc6 = $digestforumgen->create_discussion($record);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc6, $digestforum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $record->timemodified++;
        $disc7 = $digestforumgen->create_discussion($record);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc7, $digestforum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Adding timed discussions.
        $CFG->digestforum_enabletimedposts = true;
        $now = $record->timemodified;
        $past = $now - 600;
        $future = $now + 600;

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->timestart = $past;
        $record->timeend = $future;
        $record->timemodified = $now;
        $record->timemodified++;
        $disc8 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future;
        $record->timeend = 0;
        $disc9 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = 0;
        $disc10 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = $past;
        $disc11 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $past;
        $record->timeend = $future;
        $disc12 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future + 1; // Should be last post for those that can see it.
        $record->timeend = 0;
        $disc13 = $digestforumgen->create_discussion($record);

        // Admin user ignores the timed settings of discussions.
        // Post ordering taking into account timestart:
        //  8 = t
        // 10 = t+3
        // 11 = t+4
        // 12 = t+5
        //  9 = t+60
        // 13 = t+61.
        $this->setAdminUser();
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc9, $digestforum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc11, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc13, $digestforum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user can see their own timed discussions.
        $this->setUser($user);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc9, $digestforum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc11, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc13, $digestforum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user does not ignore timed settings.
        $this->setUser($user2);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Reset to normal mode.
        $CFG->digestforum_enabletimedposts = false;
        $this->setAdminUser();

        // Two discussions with identical timemodified will sort by id.
        $record->timemodified += 25;
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc3->id, 'timemodified' => $record->timemodified));
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc2->id, 'timemodified' => $record->timemodified));
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc12->id, 'timemodified' => $record->timemodified - 5));
        $disc2 = $DB->get_record('digestforum_discussions', array('id' => $disc2->id));
        $disc3 = $DB->get_record('digestforum_discussions', array('id' => $disc3->id));

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc3, $digestforum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        // Set timemodified to not be identical.
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc2->id, 'timemodified' => $record->timemodified - 1));

        // Test pinned posts behave correctly.
        $disc8->pinned = DFORUM_DISCUSSION_PINNED;
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc8->id, 'pinned' => $disc8->pinned));
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc3, $digestforum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc8->id, $neighbours['next']->id);

        // Test 3 pinned posts.
        $disc6->pinned = DFORUM_DISCUSSION_PINNED;
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc6->id, 'pinned' => $disc6->pinned));
        $disc4->pinned = DFORUM_DISCUSSION_PINNED;
        $DB->update_record('digestforum_discussions', (object) array('id' => $disc4->id, 'pinned' => $disc4->pinned));

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc6, $digestforum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEquals($disc8->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc4, $digestforum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc6->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test getting the neighbour threads of a blog-like digestforum.
     */
    public function test_digestforum_get_neighbours_blog() {
        global $CFG, $DB;
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'blog'));
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = context_module::instance($cm->id);

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->timemodified = time();
        $disc1 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc2 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc3 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc4 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $disc5 = $digestforumgen->create_discussion($record);

        // Getting the neighbours.
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc1, $digestforum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc3, $digestforum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc4->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc4, $digestforum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc5->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc5, $digestforum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Make sure that the thread's timemodified does not affect the order.
        $record->timemodified++;
        $disc1->timemodified = $record->timemodified;
        $DB->update_record('digestforum_discussions', $disc1);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc1, $digestforum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        // Add another blog post.
        $record->timemodified++;
        $disc6 = $digestforumgen->create_discussion($record);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc6, $digestforum);
        $this->assertEquals($disc5->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $record->timemodified++;
        $disc7 = $digestforumgen->create_discussion($record);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc7, $digestforum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Adding timed discussions.
        $CFG->digestforum_enabletimedposts = true;
        $now = $record->timemodified;
        $past = $now - 600;
        $future = $now + 600;

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->timestart = $past;
        $record->timeend = $future;
        $record->timemodified = $now;
        $record->timemodified++;
        $disc8 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future;
        $record->timeend = 0;
        $disc9 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = 0;
        $disc10 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = $past;
        $disc11 = $digestforumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $past;
        $record->timeend = $future;
        $disc12 = $digestforumgen->create_discussion($record);

        // Admin user ignores the timed settings of discussions.
        $this->setAdminUser();
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc9, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc11, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user can see their own timed discussions.
        $this->setUser($user);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc9, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc11, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user does not ignore timed settings.
        $this->setUser($user2);
        $neighbours = digestforum_get_discussion_neighbours($cm, $disc8, $digestforum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc10, $digestforum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc12, $digestforum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Reset to normal mode.
        $CFG->digestforum_enabletimedposts = false;
        $this->setAdminUser();

        $record->timemodified++;
        // Two blog posts with identical creation time will sort by id.
        $DB->update_record('digestforum_posts', (object) array('id' => $disc2->firstpost, 'created' => $record->timemodified));
        $DB->update_record('digestforum_posts', (object) array('id' => $disc3->firstpost, 'created' => $record->timemodified));

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc2, $digestforum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm, $disc3, $digestforum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test getting the neighbour threads of a discussion.
     */
    public function test_digestforum_get_neighbours_with_groups() {
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');
        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $user1->id, 'groupid' => $group1->id));

        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'groupmode' => VISIBLEGROUPS));
        $digestforum2 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'groupmode' => SEPARATEGROUPS));
        $cm1 = get_coursemodule_from_instance('digestforum', $digestforum1->id);
        $cm2 = get_coursemodule_from_instance('digestforum', $digestforum2->id);
        $context1 = context_module::instance($cm1->id);
        $context2 = context_module::instance($cm2->id);

        // Creating discussions in both digestforums.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group1->id;
        $record->timemodified = time();
        $disc11 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $record->timemodified++;
        $disc21 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group2->id;
        $disc12 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc22 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = null;
        $disc13 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc23 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group2->id;
        $disc14 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc24 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group1->id;
        $disc15 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc25 = $digestforumgen->create_discussion($record);

        // Admin user can see all groups.
        $this->setAdminUser();
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc12->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc22->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc12, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc22, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc22->id, $neighbours['prev']->id);
        $this->assertEquals($disc24->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc14, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc24, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc14->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc24->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Admin user is only viewing group 1.
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user viewing non-grouped posts (this is only possible in visible groups).
        $this->setUser($user1);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm1, true));

        // They can see anything in visible groups.
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc12, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);

        // Normal user, orphan of groups, can only see non-grouped posts in separate groups.
        $this->setUser($user2);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm2, true));

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEmpty($neighbours['next']);

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc22, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc24, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Switching to viewing group 1.
        $this->setUser($user1);
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        // They can see non-grouped or same group.
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Querying the neighbours of a discussion passing the wrong CM.
        $this->expectException('coding_exception');
        digestforum_get_discussion_neighbours($cm2, $disc11, $digestforum2);
    }

    /**
     * Test getting the neighbour threads of a blog-like digestforum with groups involved.
     */
    public function test_digestforum_get_neighbours_with_groups_blog() {
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');
        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $user1->id, 'groupid' => $group1->id));

        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'blog',
                'groupmode' => VISIBLEGROUPS));
        $digestforum2 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id, 'type' => 'blog',
                'groupmode' => SEPARATEGROUPS));
        $cm1 = get_coursemodule_from_instance('digestforum', $digestforum1->id);
        $cm2 = get_coursemodule_from_instance('digestforum', $digestforum2->id);
        $context1 = context_module::instance($cm1->id);
        $context2 = context_module::instance($cm2->id);

        // Creating blog posts in both digestforums.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group1->id;
        $record->timemodified = time();
        $disc11 = $digestforumgen->create_discussion($record);
        $record->timenow = $timenext++;
        $record->digestforum = $digestforum2->id;
        $record->timemodified++;
        $disc21 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group2->id;
        $disc12 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc22 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = null;
        $disc13 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc23 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group2->id;
        $disc14 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc24 = $digestforumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->groupid = $group1->id;
        $disc15 = $digestforumgen->create_discussion($record);
        $record->digestforum = $digestforum2->id;
        $disc25 = $digestforumgen->create_discussion($record);

        // Admin user can see all groups.
        $this->setAdminUser();
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc12->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc22->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc12, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc22, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc22->id, $neighbours['prev']->id);
        $this->assertEquals($disc24->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc14, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc24, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc14->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc24->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Admin user is only viewing group 1.
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user viewing non-grouped posts (this is only possible in visible groups).
        $this->setUser($user1);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm1, true));

        // They can see anything in visible groups.
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc12, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);

        // Normal user, orphan of groups, can only see non-grouped posts in separate groups.
        $this->setUser($user2);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm2, true));

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEmpty($neighbours['next']);

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc22, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc24, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Switching to viewing group 1.
        $this->setUser($user1);
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        // They can see non-grouped or same group.
        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc11, $digestforum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc21, $digestforum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc13, $digestforum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc23, $digestforum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = digestforum_get_discussion_neighbours($cm1, $disc15, $digestforum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = digestforum_get_discussion_neighbours($cm2, $disc25, $digestforum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Querying the neighbours of a discussion passing the wrong CM.
        $this->expectException('coding_exception');
        digestforum_get_discussion_neighbours($cm2, $disc11, $digestforum2);
    }

    public function test_count_discussion_replies_basic() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);

        // Count the discussion replies in the digestforum.
        $result = digestforum_count_discussion_replies($digestforum->id);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_limited() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits shouldn't make a difference.
        $result = digestforum_count_discussion_replies($digestforum->id, "", 20);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_paginated() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding paging shouldn't make any difference.
        $result = digestforum_count_discussion_replies($digestforum->id, "", -1, 0, 100);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_paginated_sorted() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Specifying the digestforumsort should also give a good result. This follows a different path.
        $result = digestforum_count_discussion_replies($digestforum->id, "d.id asc", -1, 0, 100);
        $this->assertCount(10, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_limited_sorted() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits, and a digestforumsort shouldn't make a difference.
        $result = digestforum_count_discussion_replies($digestforum->id, "d.id asc", 20);
        $this->assertCount(10, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_paginated_sorted_small() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Grabbing a smaller subset and they should be ordered as expected.
        $result = digestforum_count_discussion_replies($digestforum->id, "d.id asc", -1, 0, 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_paginated_sorted_small_reverse() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Grabbing a smaller subset and they should be ordered as expected.
        $result = digestforum_count_discussion_replies($digestforum->id, "d.id desc", -1, 0, 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the last discussionid.
            $discussionid = array_pop($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_limited_sorted_small_reverse() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits, and a digestforumsort shouldn't make a difference.
        $result = digestforum_count_discussion_replies($digestforum->id, "d.id desc", 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the last discussionid.
            $discussionid = array_pop($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }
    public function test_discussion_pinned_sort() {
        list($digestforum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $discussions = digestforum_get_discussions($cm);
        // First discussion should be pinned.
        $first = reset($discussions);
        $this->assertEquals(1, $first->pinned, "First discussion should be pinned discussion");
    }
    public function test_digestforum_view() {
        global $CFG;

        $CFG->enablecompletion = 1;
        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id),
                                                            array('completion' => 2, 'completionview' => 1));
        $context = context_module::instance($digestforum->cmid);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();

        $this->setAdminUser();
        digestforum_view($digestforum, $course, $cm, $context);

        $events = $sink->get_events();
        // 2 additional events thanks to completion.
        $this->assertCount(3, $events);
        $event = array_pop($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_digestforum\event\course_module_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $url = new \moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_name());

        // Check completion status.
        $completion = new completion_info($course);
        $completiondata = $completion->get_data($cm);
        $this->assertEquals(1, $completiondata->completionstate);

    }

    /**
     * Test digestforum_discussion_view.
     */
    public function test_digestforum_discussion_view() {
        global $CFG, $USER;

        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $discussion = $this->create_single_discussion_with_replies($digestforum, $USER, 2);

        $context = context_module::instance($digestforum->cmid);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();

        $this->setAdminUser();
        digestforum_discussion_view($context, $digestforum, $discussion);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = array_pop($events);

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
     * Create a new course, digestforum, and user with a number of discussions and replies.
     *
     * @param int $discussioncount The number of discussions to create
     * @param int $replycount The number of replies to create in each discussion
     * @return array Containing the created digestforum object, and the ids of the created discussions.
     */
    protected function create_multiple_discussions_with_replies($discussioncount, $replycount) {
        $this->resetAfterTest();

        // Setup the content.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $record = new stdClass();
        $record->course = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $record);

        // Create 10 discussions with replies.
        $discussionids = array();
        for ($i = 0; $i < $discussioncount; $i++) {
            // Pin 3rd discussion.
            if ($i == 3) {
                $discussion = $this->create_single_discussion_pinned_with_replies($digestforum, $user, $replycount);
            } else {
                $discussion = $this->create_single_discussion_with_replies($digestforum, $user, $replycount);
            }

            $discussionids[] = $discussion->id;
        }
        return array($digestforum, $discussionids);
    }

    /**
     * Create a discussion with a number of replies.
     *
     * @param object $digestforum The digestforum which has been created
     * @param object $user The user making the discussion and replies
     * @param int $replycount The number of replies
     * @return object $discussion
     */
    protected function create_single_discussion_with_replies($digestforum, $user, $replycount) {
        global $DB;

        $generator = self::getDataGenerator()->get_plugin_generator('mod_digestforum');

        $record = new stdClass();
        $record->course = $digestforum->course;
        $record->digestforum = $digestforum->id;
        $record->userid = $user->id;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $replyto = $DB->get_record('digestforum_posts', array('discussion' => $discussion->id));

        // Create the replies.
        $post = new stdClass();
        $post->userid = $user->id;
        $post->discussion = $discussion->id;
        $post->parent = $replyto->id;

        for ($i = 0; $i < $replycount; $i++) {
            $generator->create_post($post);
        }

        return $discussion;
    }
    /**
     * Create a discussion with a number of replies.
     *
     * @param object $digestforum The digestforum which has been created
     * @param object $user The user making the discussion and replies
     * @param int $replycount The number of replies
     * @return object $discussion
     */
    protected function create_single_discussion_pinned_with_replies($digestforum, $user, $replycount) {
        global $DB;

        $generator = self::getDataGenerator()->get_plugin_generator('mod_digestforum');

        $record = new stdClass();
        $record->course = $digestforum->course;
        $record->digestforum = $digestforum->id;
        $record->userid = $user->id;
        $record->pinned = DFORUM_DISCUSSION_PINNED;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $replyto = $DB->get_record('digestforum_posts', array('discussion' => $discussion->id));

        // Create the replies.
        $post = new stdClass();
        $post->userid = $user->id;
        $post->discussion = $discussion->id;
        $post->parent = $replyto->id;

        for ($i = 0; $i < $replycount; $i++) {
            $generator->create_post($post);
        }

        return $discussion;
    }

    /**
     * Tests for mod_digestforum_rating_can_see_item_ratings().
     *
     * @throws coding_exception
     * @throws rating_exception
     */
    public function test_mod_digestforum_rating_can_see_item_ratings() {
        global $DB;

        $this->resetAfterTest();

        // Setup test data.
        $course = new stdClass();
        $course->groupmode = SEPARATEGROUPS;
        $course->groupmodeforce = true;
        $course = $this->getDataGenerator()->create_course($course);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));
        $generator = self::getDataGenerator()->get_plugin_generator('mod_digestforum');
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = context_module::instance($cm->id);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $user4 = $this->getDataGenerator()->create_user();

        // Groups and stuff.
        $role = $DB->get_record('role', array('shortname' => 'teacher'), '*', MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user4->id, $course->id, $role->id);

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        groups_add_member($group1, $user1);
        groups_add_member($group1, $user2);
        groups_add_member($group2, $user3);
        groups_add_member($group2, $user4);

        $record = new stdClass();
        $record->course = $digestforum->course;
        $record->digestforum = $digestforum->id;
        $record->userid = $user1->id;
        $record->groupid = $group1->id;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $post = $DB->get_record('digestforum_posts', array('discussion' => $discussion->id));

        $ratingoptions = new stdClass;
        $ratingoptions->context = $context;
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->component = 'mod_digestforum';
        $ratingoptions->itemid  = $post->id;
        $ratingoptions->scaleid = 2;
        $ratingoptions->userid  = $user2->id;
        $rating = new rating($ratingoptions);
        $rating->update_rating(2);

        // Now try to access it as various users.
        unassign_capability('moodle/site:accessallgroups', $role->id);
        $params = array('contextid' => 2,
                        'component' => 'mod_digestforum',
                        'ratingarea' => 'post',
                        'itemid' => $post->id,
                        'scaleid' => 2);
        $this->setUser($user1);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertFalse(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertFalse(mod_digestforum_rating_can_see_item_ratings($params));

        // Now try with accessallgroups cap and make sure everything is visible.
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $role->id, $context->id);
        $this->setUser($user1);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));

        // Change group mode and verify visibility.
        $course->groupmode = VISIBLEGROUPS;
        $DB->update_record('course', $course);
        unassign_capability('moodle/site:accessallgroups', $role->id);
        $this->setUser($user1);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertTrue(mod_digestforum_rating_can_see_item_ratings($params));

    }

    /**
     * Test digestforum_get_discussions
     */
    public function test_digestforum_get_discussions_with_groups() {
        global $DB;

        $this->resetAfterTest(true);

        // Create course to add the module.
        $course = self::getDataGenerator()->create_course(array('groupmode' => VISIBLEGROUPS, 'groupmodeforce' => 0));
        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $user3 = self::getDataGenerator()->create_user();

        $role = $DB->get_record('role', array('shortname' => 'student'), '*', MUST_EXIST);
        self::getDataGenerator()->enrol_user($user1->id, $course->id, $role->id);
        self::getDataGenerator()->enrol_user($user2->id, $course->id, $role->id);
        self::getDataGenerator()->enrol_user($user3->id, $course->id, $role->id);

        // Forum forcing separate gropus.
        $record = new stdClass();
        $record->course = $course->id;
        $digestforum = self::getDataGenerator()->create_module('digestforum', $record, array('groupmode' => SEPARATEGROUPS));
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        // Create groups.
        $group1 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group1'));
        $group2 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group2'));
        $group3 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group3'));

        // Add the user1 to g1 and g2 groups.
        groups_add_member($group1->id, $user1->id);
        groups_add_member($group2->id, $user1->id);

        // Add the user 2 and 3 to only one group.
        groups_add_member($group1->id, $user2->id);
        groups_add_member($group3->id, $user3->id);

        // Add a few discussions.
        $record = array();
        $record['course'] = $course->id;
        $record['digestforum'] = $digestforum->id;
        $record['userid'] = $user1->id;
        $record['groupid'] = $group1->id;
        $discussiong1u1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $record['groupid'] = $group2->id;
        $discussiong2u1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $record['userid'] = $user2->id;
        $record['groupid'] = $group1->id;
        $discussiong1u2 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $record['userid'] = $user3->id;
        $record['groupid'] = $group3->id;
        $discussiong3u3 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        self::setUser($user1);

        // Test retrieve discussions not passing the groupid parameter. We will receive only first group discussions.
        $discussions = digestforum_get_discussions($cm);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my discussions.
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, 0);
        self::assertCount(3, $discussions);

        // Get all my g1 discussions.
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group1->id);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my g2 discussions.
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group2->id);
        self::assertCount(1, $discussions);
        $discussion = array_shift($discussions);
        self::assertEquals($group2->id, $discussion->groupid);
        self::assertEquals($user1->id, $discussion->userid);
        self::assertEquals($discussiong2u1->id, $discussion->discussion);

        // Get all my g3 discussions (I'm not enrolled in that group).
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id);
        self::assertCount(0, $discussions);

        // This group does not exist.
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id + 1000);
        self::assertCount(0, $discussions);

        self::setUser($user2);

        // Test retrieve discussions not passing the groupid parameter. We will receive only first group discussions.
        $discussions = digestforum_get_discussions($cm);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my viewable discussions.
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, 0);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my g2 discussions (I'm not enrolled in that group).
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group2->id);
        self::assertCount(0, $discussions);

        // Get all my g3 discussions (I'm not enrolled in that group).
        $discussions = digestforum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id);
        self::assertCount(0, $discussions);

    }

    /**
     * Test digestforum_user_can_post_discussion
     */
    public function test_digestforum_user_can_post_discussion() {
        global $CFG, $DB;

        $this->resetAfterTest(true);

        // Create course to add the module.
        $course = self::getDataGenerator()->create_course(array('groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1));
        $user = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Forum forcing separate gropus.
        $record = new stdClass();
        $record->course = $course->id;
        $digestforum = self::getDataGenerator()->create_module('digestforum', $record, array('groupmode' => SEPARATEGROUPS));
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = context_module::instance($cm->id);

        self::setUser($user);

        // The user is not enroled in any group, try to post in a digestforum with separate groups.
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Create a group.
        $group = $this->getDataGenerator()->create_group(array('courseid' => $course->id));

        // Try to post in a group the user is not enrolled.
        $can = digestforum_user_can_post_discussion($digestforum, $group->id, -1, $cm, $context);
        $this->assertFalse($can);

        // Add the user to a group.
        groups_add_member($group->id, $user->id);

        // Try to post in a group the user is not enrolled.
        $can = digestforum_user_can_post_discussion($digestforum, $group->id + 1, -1, $cm, $context);
        $this->assertFalse($can);

        // Now try to post in the user group. (null means it will guess the group).
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertTrue($can);

        $can = digestforum_user_can_post_discussion($digestforum, $group->id, -1, $cm, $context);
        $this->assertTrue($can);

        // Test all groups.
        $can = digestforum_user_can_post_discussion($digestforum, -1, -1, $cm, $context);
        $this->assertFalse($can);

        $this->setAdminUser();
        $can = digestforum_user_can_post_discussion($digestforum, -1, -1, $cm, $context);
        $this->assertTrue($can);

        // Change digestforum type.
        $digestforum->type = 'news';
        $DB->update_record('digestforum', $digestforum);

        // Admin can post news.
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertTrue($can);

        // Normal users don't.
        self::setUser($user);
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Change digestforum type.
        $digestforum->type = 'eachuser';
        $DB->update_record('digestforum', $digestforum);

        // I didn't post yet, so I should be able to post.
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertTrue($can);

        // Post now.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->groupid = $group->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // I already posted, I shouldn't be able to post.
        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Last check with no groups, normal digestforum and course.
        $course->groupmode = NOGROUPS;
        $course->groupmodeforce = 0;
        $DB->update_record('course', $course);

        $digestforum->type = 'general';
        $digestforum->groupmode = NOGROUPS;
        $DB->update_record('digestforum', $digestforum);

        $can = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
        $this->assertTrue($can);
    }

    /**
     * Test digestforum_user_has_posted_discussion with no groups.
     */
    public function test_digestforum_user_has_posted_discussion_no_groups() {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $other = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);
        $digestforum = self::getDataGenerator()->create_module('digestforum', (object) ['course' => $course->id ]);

        self::setUser($author);

        // Neither user has posted.
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $author->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $other->id));

        // Post in the digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // The author has now posted, but the other user has not.
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $other->id));
    }

    /**
     * Test digestforum_user_has_posted_discussion with multiple digestforums
     */
    public function test_digestforum_user_has_posted_discussion_multiple_digestforums() {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', (object) ['course' => $course->id ]);
        $digestforum2 = self::getDataGenerator()->create_module('digestforum', (object) ['course' => $course->id ]);

        self::setUser($author);

        // No post in either digestforum.
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum1->id, $author->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum2->id, $author->id));

        // Post in the digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum1->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // The author has now posted in digestforum1, but not digestforum2.
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum1->id, $author->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum2->id, $author->id));
    }

    /**
     * Test digestforum_user_has_posted_discussion with multiple groups.
     */
    public function test_digestforum_user_has_posted_discussion_multiple_groups() {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        groups_add_member($group1->id, $author->id);
        groups_add_member($group2->id, $author->id);

        $digestforum = self::getDataGenerator()->create_module('digestforum', (object) ['course' => $course->id ], [
                    'groupmode' => SEPARATEGROUPS,
                ]);

        self::setUser($author);

        // The user has not posted in either group.
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $author->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group1->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group2->id));

        // Post in one group.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum->id;
        $record->groupid = $group1->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // The author has now posted in one group, but the other user has not.
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id));
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group1->id));
        $this->assertFalse(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group2->id));

        // Post in the other group.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum->id;
        $record->groupid = $group2->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // The author has now posted in one group, but the other user has not.
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id));
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group1->id));
        $this->assertTrue(digestforum_user_has_posted_discussion($digestforum->id, $author->id, $group2->id));
    }

    /**
     * Tests the mod_digestforum_myprofile_navigation() function.
     */
    public function test_mod_digestforum_myprofile_navigation() {
        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set as the current user.
        $this->setUser($user);

        // Check the node tree is correct.
        mod_digestforum_myprofile_navigation($tree, $user, $iscurrentuser, $course);
        $reflector = new ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $nodes->setAccessible(true);
        $this->assertArrayHasKey('digestforumposts', $nodes->getValue($tree));
        $this->assertArrayHasKey('digestforumdiscussions', $nodes->getValue($tree));
    }

    /**
     * Tests the mod_digestforum_myprofile_navigation() function as a guest.
     */
    public function test_mod_digestforum_myprofile_navigation_as_guest() {
        global $USER;

        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set user as guest.
        $this->setGuestUser();

        // Check the node tree is correct.
        mod_digestforum_myprofile_navigation($tree, $USER, $iscurrentuser, $course);
        $reflector = new ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $nodes->setAccessible(true);
        $this->assertArrayNotHasKey('digestforumposts', $nodes->getValue($tree));
        $this->assertArrayNotHasKey('digestforumdiscussions', $nodes->getValue($tree));
    }

    /**
     * Tests the mod_digestforum_myprofile_navigation() function as a user viewing another user's profile.
     */
    public function test_mod_digestforum_myprofile_navigation_different_user() {
        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set to different user's profile.
        $this->setUser($user2);

        // Check the node tree is correct.
        mod_digestforum_myprofile_navigation($tree, $user, $iscurrentuser, $course);
        $reflector = new ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $nodes->setAccessible(true);
        $this->assertArrayHasKey('digestforumposts', $nodes->getValue($tree));
        $this->assertArrayHasKey('digestforumdiscussions', $nodes->getValue($tree));
    }

    public function test_print_overview() {
        $this->resetAfterTest();
        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course();

        // Create an author user.
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);
        $this->getDataGenerator()->enrol_user($author->id, $course2->id);

        // Create a viewer user.
        $viewer = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer->id, $course1->id);
        $this->getDataGenerator()->enrol_user($viewer->id, $course2->id);

        // Create two digestforums - one in each course.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', (object) array('course' => $course1->id));
        $digestforum2 = self::getDataGenerator()->create_module('digestforum', (object) array('course' => $course2->id));

        // A standard post in the digestforum.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum1->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $this->setUser($viewer->id);
        $courses = array(
            $course1->id => clone $course1,
            $course2->id => clone $course2,
        );

        foreach ($courses as $courseid => $course) {
            $courses[$courseid]->lastaccess = 0;
        }
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        // There should be one entry for course1, and no others.
        $this->assertCount(1, $results);

        // There should be one entry for a digestforum in course1.
        $this->assertCount(1, $results[$course1->id]);
        $this->assertArrayHasKey('digestforum', $results[$course1->id]);
    }

    public function test_print_overview_groups() {
        $this->resetAfterTest();
        $course1 = self::getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));

        // Create an author user.
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);

        // Create two viewer users - one in each group.
        $viewer1 = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer1->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer1->id, 'groupid' => $group1->id));

        $viewer2 = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer2->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer2->id, 'groupid' => $group2->id));

        // Create a digestforum.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', (object) array(
            'course'        => $course1->id,
            'groupmode'     => SEPARATEGROUPS,
        ));

        // A post in the digestforum for group1.
        $record = new stdClass();
        $record->course     = $course1->id;
        $record->userid     = $author->id;
        $record->digestforum      = $digestforum1->id;
        $record->groupid    = $group1->id;
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $course1->lastaccess = 0;
        $courses = array($course1->id => $course1);

        // As viewer1 (same group as post).
        $this->setUser($viewer1->id);
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        // There should be one entry for course1.
        $this->assertCount(1, $results);

        // There should be one entry for a digestforum in course1.
        $this->assertCount(1, $results[$course1->id]);
        $this->assertArrayHasKey('digestforum', $results[$course1->id]);

        $this->setUser($viewer2->id);
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        // There should be one entry for course1.
        $this->assertCount(0, $results);
    }

    /**
     * @dataProvider print_overview_timed_provider
     */
    public function test_print_overview_timed($config, $hasresult) {
        $this->resetAfterTest();
        $course1 = self::getDataGenerator()->create_course();

        // Create an author user.
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);

        // Create a viewer user.
        $viewer = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer->id, $course1->id);

        // Create a digestforum.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', (object) array('course' => $course1->id));

        // A timed post with a timestart in the past (24 hours ago).
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $author->id;
        $record->digestforum = $digestforum1->id;
        if (isset($config['timestartmodifier'])) {
            $record->timestart = time() + $config['timestartmodifier'];
        }
        if (isset($config['timeendmodifier'])) {
            $record->timeend = time() + $config['timeendmodifier'];
        }
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $course1->lastaccess = 0;
        $courses = array($course1->id => $course1);

        // As viewer, check the digestforum_print_overview result.
        $this->setUser($viewer->id);
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        if ($hasresult) {
            // There should be one entry for course1.
            $this->assertCount(1, $results);

            // There should be one entry for a digestforum in course1.
            $this->assertCount(1, $results[$course1->id]);
            $this->assertArrayHasKey('digestforum', $results[$course1->id]);
        } else {
            // There should be no entries for any course.
            $this->assertCount(0, $results);
        }
    }

    /**
     * @dataProvider print_overview_timed_provider
     */
    public function test_print_overview_timed_groups($config, $hasresult) {
        $this->resetAfterTest();
        $course1 = self::getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));

        // Create an author user.
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);

        // Create two viewer users - one in each group.
        $viewer1 = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer1->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer1->id, 'groupid' => $group1->id));

        $viewer2 = self::getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer2->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer2->id, 'groupid' => $group2->id));

        // Create a digestforum.
        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', (object) array(
            'course'        => $course1->id,
            'groupmode'     => SEPARATEGROUPS,
        ));

        // A post in the digestforum for group1.
        $record = new stdClass();
        $record->course     = $course1->id;
        $record->userid     = $author->id;
        $record->digestforum      = $digestforum1->id;
        $record->groupid    = $group1->id;
        if (isset($config['timestartmodifier'])) {
            $record->timestart = time() + $config['timestartmodifier'];
        }
        if (isset($config['timeendmodifier'])) {
            $record->timeend = time() + $config['timeendmodifier'];
        }
        $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        $course1->lastaccess = 0;
        $courses = array($course1->id => $course1);

        // As viewer1 (same group as post).
        $this->setUser($viewer1->id);
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        if ($hasresult) {
            // There should be one entry for course1.
            $this->assertCount(1, $results);

            // There should be one entry for a digestforum in course1.
            $this->assertCount(1, $results[$course1->id]);
            $this->assertArrayHasKey('digestforum', $results[$course1->id]);
        } else {
            // There should be no entries for any course.
            $this->assertCount(0, $results);
        }

        $this->setUser($viewer2->id);
        $results = array();
        digestforum_print_overview($courses, $results);
        $this->assertDebuggingCalledCount(2);

        // There should be one entry for course1.
        $this->assertCount(0, $results);
    }

    public function print_overview_timed_provider() {
        return array(
            'timestart_past' => array(
                'discussionconfig' => array(
                    'timestartmodifier' => -86000,
                ),
                'hasresult'         => true,
            ),
            'timestart_future' => array(
                'discussionconfig' => array(
                    'timestartmodifier' => 86000,
                ),
                'hasresult'         => false,
            ),
            'timeend_past' => array(
                'discussionconfig' => array(
                    'timeendmodifier'   => -86000,
                ),
                'hasresult'         => false,
            ),
            'timeend_future' => array(
                'discussionconfig' => array(
                    'timeendmodifier'   => 86000,
                ),
                'hasresult'         => true,
            ),
        );
    }

    /**
     * Test test_pinned_discussion_with_group.
     */
    public function test_pinned_discussion_with_group() {
        global $SESSION;

        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));

        // Create an author user.
        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);

        // Create two viewer users - one in a group, one not.
        $viewer1 = $this->getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer1->id, $course1->id);

        $viewer2 = $this->getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer2->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer2->id, 'groupid' => $group1->id));

        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', (object) array(
            'course' => $course1->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('digestforum', $digestforum1->id);

        $alldiscussions = array();
        $group1discussions = array();

        // Create 4 discussions in all participants group and group1, where the first
        // discussion is pinned in each group.
        $allrecord = new stdClass();
        $allrecord->course = $course1->id;
        $allrecord->userid = $author->id;
        $allrecord->digestforum = $digestforum1->id;
        $allrecord->pinned = DFORUM_DISCUSSION_PINNED;

        $group1record = new stdClass();
        $group1record->course = $course1->id;
        $group1record->userid = $author->id;
        $group1record->digestforum = $digestforum1->id;
        $group1record->groupid = $group1->id;
        $group1record->pinned = DFORUM_DISCUSSION_PINNED;

        $alldiscussions[] = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($allrecord);
        $group1discussions[] = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($group1record);

        // Create unpinned discussions.
        $allrecord->pinned = DFORUM_DISCUSSION_UNPINNED;
        $group1record->pinned = DFORUM_DISCUSSION_UNPINNED;
        for ($i = 0; $i < 3; $i++) {
            $alldiscussions[] = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($allrecord);
            $group1discussions[] = $this->getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($group1record);
        }

        // As viewer1 (no group). This user shouldn't see any of group1's discussions
        // so their expected discussion order is (where rightmost is highest priority):
        // Ad1, ad2, ad3, ad0.
        $this->setUser($viewer1->id);

        // CHECK 1.
        // Take the neighbours of ad3, which should be prev: ad2 and next: ad0.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $alldiscussions[3], $digestforum1);
        // Ad2 check.
        $this->assertEquals($alldiscussions[2]->id, $neighbours['prev']->id);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['next']->id);

        // CHECK 2.
        // Take the neighbours of ad0, which should be prev: ad3 and next: null.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $alldiscussions[0], $digestforum1);
        // Ad3 check.
        $this->assertEquals($alldiscussions[3]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);

        // CHECK 3.
        // Take the neighbours of ad1, which should be prev: null and next: ad2.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $alldiscussions[1], $digestforum1);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // Ad2 check.
        $this->assertEquals($alldiscussions[2]->id, $neighbours['next']->id);

        // Temporary hack to workaround for MDL-52656.
        $SESSION->currentgroup = null;

        // As viewer2 (group1). This user should see all of group1's posts and the all participants group.
        // The expected discussion order is (rightmost is highest priority):
        // Ad1, gd1, ad2, gd2, ad3, gd3, ad0, gd0.
        $this->setUser($viewer2->id);

        // CHECK 1.
        // Take the neighbours of ad1, which should be prev: null and next: gd1.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $alldiscussions[1], $digestforum1);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // Gd1 check.
        $this->assertEquals($group1discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        // Take the neighbours of ad3, which should be prev: gd2 and next: gd3.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $alldiscussions[3], $digestforum1);
        // Gd2 check.
        $this->assertEquals($group1discussions[2]->id, $neighbours['prev']->id);
        // Gd3 check.
        $this->assertEquals($group1discussions[3]->id, $neighbours['next']->id);

        // CHECK 3.
        // Take the neighbours of gd3, which should be prev: ad3 and next: ad0.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $group1discussions[3], $digestforum1);
        // Ad3 check.
        $this->assertEquals($alldiscussions[3]->id, $neighbours['prev']->id);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['next']->id);

        // CHECK 4.
        // Take the neighbours of gd0, which should be prev: ad0 and next: null.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $group1discussions[0], $digestforum1);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test test_pinned_with_timed_discussions.
     */
    public function test_pinned_with_timed_discussions() {
        global $CFG;

        $CFG->digestforum_enabletimedposts = true;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Create an user.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create a digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', (object) array(
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $now = time();
        $discussions = array();
        $discussiongenerator = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->pinned = DFORUM_DISCUSSION_PINNED;
        $record->timemodified = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = DFORUM_DISCUSSION_UNPINNED;
        $record->timestart = $now + 10;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->timestart = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        // Expected order of discussions:
        // D2, d1, d0.
        $this->setUser($user->id);

        // CHECK 1.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[2], $digestforum);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[1], $digestforum);
        // D2 check.
        $this->assertEquals($discussions[2]->id, $neighbours['prev']->id);
        // D0 check.
        $this->assertEquals($discussions[0]->id, $neighbours['next']->id);

        // CHECK 3.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[0], $digestforum);
        // D2 check.
        $this->assertEquals($discussions[1]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test test_pinned_timed_discussions_with_timed_discussions.
     */
    public function test_pinned_timed_discussions_with_timed_discussions() {
        global $CFG;

        $CFG->digestforum_enabletimedposts = true;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Create an user.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create a digestforum.
        $record = new stdClass();
        $record->course = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', (object) array(
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $now = time();
        $discussions = array();
        $discussiongenerator = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        $record->pinned = DFORUM_DISCUSSION_PINNED;
        $record->timemodified = $now;
        $record->timestart = $now + 10;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = DFORUM_DISCUSSION_UNPINNED;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->timestart = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = DFORUM_DISCUSSION_PINNED;

        $discussions[] = $discussiongenerator->create_discussion($record);

        // Expected order of discussions:
        // D2, d1, d3, d0.
        $this->setUser($user->id);

        // CHECK 1.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[2], $digestforum);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[1], $digestforum);
        // D2 check.
        $this->assertEquals($discussions[2]->id, $neighbours['prev']->id);
        // D3 check.
        $this->assertEquals($discussions[3]->id, $neighbours['next']->id);

        // CHECK 3.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[3], $digestforum);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['prev']->id);
        // D0 check.
        $this->assertEquals($discussions[0]->id, $neighbours['next']->id);

        // CHECK 4.
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $discussions[0], $digestforum);
        // D3 check.
        $this->assertEquals($discussions[3]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * @dataProvider digestforum_get_unmailed_posts_provider
     */
    public function test_digestforum_get_unmailed_posts($discussiondata, $enabletimedposts, $expectedcount, $expectedreplycount) {
        global $CFG, $DB;

        $this->resetAfterTest();

        // Configure timed posts.
        $CFG->digestforum_enabletimedposts = $enabletimedposts;

        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $digestforumgen = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');

        // Keep track of the start time of the test. Do not use time() after this point to prevent random failures.
        $time = time();

        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum->id;
        if (isset($discussiondata['timecreated'])) {
            $record->timemodified = $time + $discussiondata['timecreated'];
        }
        if (isset($discussiondata['timestart'])) {
            $record->timestart = $time + $discussiondata['timestart'];
        }
        if (isset($discussiondata['timeend'])) {
            $record->timeend = $time + $discussiondata['timeend'];
        }
        if (isset($discussiondata['mailed'])) {
            $record->mailed = $discussiondata['mailed'];
        }

        $discussion = $digestforumgen->create_discussion($record);

        // Fetch the unmailed posts.
        $timenow   = $time;
        $endtime   = $timenow - $CFG->maxeditingtime;
        $starttime = $endtime - 2 * DAYSECS;

        $unmailed = digestforum_get_unmailed_posts($starttime, $endtime, $timenow);
        $this->assertCount($expectedcount, $unmailed);

        // Add a reply just outside the maxeditingtime.
        $replyto = $DB->get_record('digestforum_posts', array('discussion' => $discussion->id));
        $reply = new stdClass();
        $reply->userid = $user->id;
        $reply->discussion = $discussion->id;
        $reply->parent = $replyto->id;
        $reply->created = max($replyto->created, $endtime - 1);
        $digestforumgen->create_post($reply);

        $unmailed = digestforum_get_unmailed_posts($starttime, $endtime, $timenow);
        $this->assertCount($expectedreplycount, $unmailed);
    }

    /**
     * Test for digestforum_is_author_hidden.
     */
    public function test_digestforum_is_author_hidden() {
        // First post, different digestforum type.
        $post = (object) ['parent' => 0];
        $digestforum = (object) ['type' => 'standard'];
        $this->assertFalse(digestforum_is_author_hidden($post, $digestforum));

        // Child post, different digestforum type.
        $post->parent = 1;
        $this->assertFalse(digestforum_is_author_hidden($post, $digestforum));

        // First post, single simple discussion digestforum type.
        $post->parent = 0;
        $digestforum->type = 'single';
        $this->assertTrue(digestforum_is_author_hidden($post, $digestforum));

        // Child post, single simple discussion digestforum type.
        $post->parent = 1;
        $this->assertFalse(digestforum_is_author_hidden($post, $digestforum));

        // Incorrect parameters: $post.
        $this->expectException('coding_exception');
        $this->expectExceptionMessage('$post->parent must be set.');
        unset($post->parent);
        digestforum_is_author_hidden($post, $digestforum);

        // Incorrect parameters: $digestforum.
        $this->expectException('coding_exception');
        $this->expectExceptionMessage('$digestforum->type must be set.');
        unset($digestforum->type);
        digestforum_is_author_hidden($post, $digestforum);
    }

    public function digestforum_get_unmailed_posts_provider() {
        return [
            'Untimed discussion; Single post; maxeditingtime not expired' => [
                'discussion'        => [
                ],
                'timedposts'        => false,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
            'Untimed discussion; Single post; maxeditingtime expired' => [
                'discussion'        => [
                    'timecreated'   => - DAYSECS,
                ],
                'timedposts'        => false,
                'postcount'         => 1,
                'replycount'        => 2,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime not expired' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => 0,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                ],
                'timedposts'        => true,
                'postcount'         => 1,
                'replycount'        => 2,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired; timeend not reached' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                    'timeend'       => + DAYSECS
                ],
                'timedposts'        => true,
                'postcount'         => 1,
                'replycount'        => 2,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired; timeend passed' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                    'timeend'       => - HOURSECS,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timeend not reached' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timeend'       => + DAYSECS
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 1,
            ],
            'Timed discussion; Single post; Posted 1 week ago; timeend passed' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timeend'       => - DAYSECS,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],

            'Previously mailed; Untimed discussion; Single post; maxeditingtime not expired' => [
                'discussion'        => [
                    'mailed'        => 1,
                ],
                'timedposts'        => false,
                'postcount'         => 0,
                'replycount'        => 0,
            ],

            'Previously mailed; Untimed discussion; Single post; maxeditingtime expired' => [
                'discussion'        => [
                    'timecreated'   => - DAYSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => false,
                'postcount'         => 0,
                'replycount'        => 1,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime not expired' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => 0,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 1,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired; timeend not reached' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                    'timeend'       => + DAYSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 1,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timestart maxeditingtime expired; timeend passed' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timestart'     => - DAYSECS,
                    'timeend'       => - HOURSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timeend not reached' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timeend'       => + DAYSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 1,
            ],
            'Previously mailed; Timed discussion; Single post; Posted 1 week ago; timeend passed' => [
                'discussion'        => [
                    'timecreated'   => - WEEKSECS,
                    'timeend'       => - DAYSECS,
                    'mailed'        => 1,
                ],
                'timedposts'        => true,
                'postcount'         => 0,
                'replycount'        => 0,
            ],
        ];
    }

    /**
     * Test the digestforum_discussion_is_locked function.
     *
     * @dataProvider digestforum_discussion_is_locked_provider
     * @param   stdClass    $digestforum
     * @param   stdClass    $discussion
     * @param   bool        $expect
     */
    public function test_digestforum_discussion_is_locked($digestforum, $discussion, $expect) {
        $this->assertEquals($expect, digestforum_discussion_is_locked($digestforum, $discussion));
    }

    /**
     * Dataprovider for digestforum_discussion_is_locked tests.
     *
     * @return  array
     */
    public function digestforum_discussion_is_locked_provider() {
        return [
            'Unlocked: lockdiscussionafter is unset' => [
                (object) [],
                (object) [],
                false
            ],
            'Unlocked: lockdiscussionafter is false' => [
                (object) ['lockdiscussionafter' => false],
                (object) [],
                false
            ],
            'Unlocked: lockdiscussionafter is null' => [
                (object) ['lockdiscussionafter' => null],
                (object) [],
                false
            ],
            'Unlocked: lockdiscussionafter is set; digestforum is of type single; post is recent' => [
                (object) ['lockdiscussionafter' => DAYSECS, 'type' => 'single'],
                (object) ['timemodified' => time()],
                false
            ],
            'Unlocked: lockdiscussionafter is set; digestforum is of type single; post is old' => [
                (object) ['lockdiscussionafter' => MINSECS, 'type' => 'single'],
                (object) ['timemodified' => time() - DAYSECS],
                false
            ],
            'Unlocked: lockdiscussionafter is set; digestforum is of type eachuser; post is recent' => [
                (object) ['lockdiscussionafter' => DAYSECS, 'type' => 'eachuser'],
                (object) ['timemodified' => time()],
                false
            ],
            'Locked: lockdiscussionafter is set; digestforum is of type eachuser; post is old' => [
                (object) ['lockdiscussionafter' => MINSECS, 'type' => 'eachuser'],
                (object) ['timemodified' => time() - DAYSECS],
                true
            ],
        ];
    }

    /**
     * Test that {@link digestforum_update_post()} keeps correct digestforum_discussions usermodified.
     */
    public function test_digestforum_update_post_keeps_discussions_usermodified() {
        global $DB;

        $this->resetAfterTest();

        // Let there be light.
        $teacher = self::getDataGenerator()->create_user();
        $student = self::getDataGenerator()->create_user();
        $course = self::getDataGenerator()->create_course();

        $digestforum = self::getDataGenerator()->create_module('digestforum', (object)[
            'course' => $course->id,
        ]);

        $generator = self::getDataGenerator()->get_plugin_generator('mod_digestforum');

        // Let the teacher start a discussion.
        $discussion = $generator->create_discussion((object)[
            'course' => $course->id,
            'userid' => $teacher->id,
            'digestforum' => $digestforum->id,
        ]);

        // On this freshly created discussion, the teacher is the author of the last post.
        $this->assertEquals($teacher->id, $DB->get_field('digestforum_discussions', 'usermodified', ['id' => $discussion->id]));

        // Fetch modified timestamp of the discussion.
        $discussionmodified = $DB->get_field('digestforum_discussions', 'timemodified', ['id' => $discussion->id]);
        $pasttime = $discussionmodified - 3600;

        // Adjust the discussion modified timestamp back an hour, so it's in the past.
        $adjustment = (object)[
            'id' => $discussion->id,
            'timemodified' => $pasttime,
        ];
        $DB->update_record('digestforum_discussions', $adjustment);

        // Let the student reply to the teacher's post.
        $reply = $generator->create_post((object)[
            'course' => $course->id,
            'userid' => $student->id,
            'digestforum' => $digestforum->id,
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
        ]);

        // The student should now be the last post's author.
        $this->assertEquals($student->id, $DB->get_field('digestforum_discussions', 'usermodified', ['id' => $discussion->id]));

        // Fetch modified timestamp of the discussion and student's post.
        $discussionmodified = $DB->get_field('digestforum_discussions', 'timemodified', ['id' => $discussion->id]);
        $postmodified = $DB->get_field('digestforum_posts', 'modified', ['id' => $reply->id]);

        // Discussion modified time should be updated to be equal to the newly created post's time.
        $this->assertEquals($discussionmodified, $postmodified);

        // Adjust the discussion and post timestamps, so they are in the past.
        $adjustment = (object)[
            'id' => $discussion->id,
            'timemodified' => $pasttime,
        ];
        $DB->update_record('digestforum_discussions', $adjustment);

        $adjustment = (object)[
            'id' => $reply->id,
            'modified' => $pasttime,
        ];
        $DB->update_record('digestforum_posts', $adjustment);

        // The discussion and student's post time should now be an hour in the past.
        $this->assertEquals($pasttime, $DB->get_field('digestforum_discussions', 'timemodified', ['id' => $discussion->id]));
        $this->assertEquals($pasttime, $DB->get_field('digestforum_posts', 'modified', ['id' => $reply->id]));

        // Let the teacher edit the student's reply.
        $this->setUser($teacher->id);
        $newpost = (object)[
            'id' => $reply->id,
            'itemid' => 0,
            'subject' => 'Amended subject',
        ];
        digestforum_update_post($newpost, null);

        // The student should still be the last post's author.
        $this->assertEquals($student->id, $DB->get_field('digestforum_discussions', 'usermodified', ['id' => $discussion->id]));

        // The discussion modified time should not have changed.
        $this->assertEquals($pasttime, $DB->get_field('digestforum_discussions', 'timemodified', ['id' => $discussion->id]));

        // The post time should be updated.
        $this->assertGreaterThan($pasttime, $DB->get_field('digestforum_posts', 'modified', ['id' => $reply->id]));
    }

    public function test_digestforum_core_calendar_provide_event_action() {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id,
            'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(7, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_digestforum_core_calendar_provide_event_action_in_hidden_section() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id,
                'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
                \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Set sections 0 as hidden.
        set_section_visible($course->id, 0, 0);

        // Now, log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event is not shown at all.
        $this->assertNull($actionevent);
    }

    public function test_digestforum_core_calendar_provide_event_action_for_user() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id,
                'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
                \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Now log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(7, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_digestforum_core_calendar_provide_event_action_as_non_user() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Log out the user and set force login to true.
        \core\session\manager::init_empty_session();
        $CFG->forcelogin = true;

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_digestforum_core_calendar_provide_event_action_already_completed() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->enablecompletion = 1;

        // Create the activity.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Get some additional data.
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed.
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_digestforum_core_calendar_provide_event_action_already_completed_for_user() {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->enablecompletion = 1;

        // Create a course.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $digestforum = $this->getDataGenerator()->create_module('digestforum', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Get some additional data.
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $digestforum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed for the student.
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm, $student->id);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_digestforum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_mod_digestforum_get_tagged_posts() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Setup test data.
        $digestforumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');
        $course3 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course1 = $this->getDataGenerator()->create_course();
        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course1->id));
        $digestforum2 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course2->id));
        $digestforum3 = $this->getDataGenerator()->create_module('digestforum', array('course' => $course3->id));
        $post11 = $digestforumgenerator->create_content($digestforum1, array('tags' => array('Cats', 'Dogs')));
        $post12 = $digestforumgenerator->create_content($digestforum1, array('tags' => array('Cats', 'mice')));
        $post13 = $digestforumgenerator->create_content($digestforum1, array('tags' => array('Cats')));
        $post14 = $digestforumgenerator->create_content($digestforum1);
        $post15 = $digestforumgenerator->create_content($digestforum1, array('tags' => array('Cats')));
        $post16 = $digestforumgenerator->create_content($digestforum1, array('tags' => array('Cats'), 'hidden' => true));
        $post21 = $digestforumgenerator->create_content($digestforum2, array('tags' => array('Cats')));
        $post22 = $digestforumgenerator->create_content($digestforum2, array('tags' => array('Cats', 'Dogs')));
        $post23 = $digestforumgenerator->create_content($digestforum2, array('tags' => array('mice', 'Cats')));
        $post31 = $digestforumgenerator->create_content($digestforum3, array('tags' => array('mice', 'Cats')));

        $tag = core_tag_tag::get_by_name(0, 'Cats');

        // Admin can see everything.
        $res = mod_digestforum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */0);
        $this->assertRegExp('/'.$post11->subject.'</', $res->content);
        $this->assertRegExp('/'.$post12->subject.'</', $res->content);
        $this->assertRegExp('/'.$post13->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post14->subject.'</', $res->content);
        $this->assertRegExp('/'.$post15->subject.'</', $res->content);
        $this->assertRegExp('/'.$post16->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post21->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post22->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post23->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post31->subject.'</', $res->content);
        $this->assertEmpty($res->prevpageurl);
        $this->assertNotEmpty($res->nextpageurl);
        $res = mod_digestforum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */1);
        $this->assertNotRegExp('/'.$post11->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post12->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post13->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post14->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post15->subject.'</', $res->content);
        $this->assertNotRegExp('/'.$post16->subject.'</', $res->content);
        $this->assertRegExp('/'.$post21->subject.'</', $res->content);
        $this->assertRegExp('/'.$post22->subject.'</', $res->content);
        $this->assertRegExp('/'.$post23->subject.'</', $res->content);
        $this->assertRegExp('/'.$post31->subject.'</', $res->content);
        $this->assertNotEmpty($res->prevpageurl);
        $this->assertEmpty($res->nextpageurl);

        // Create and enrol a user.
        $student = self::getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($student->id, $course1->id, $studentrole->id, 'manual');
        $this->getDataGenerator()->enrol_user($student->id, $course2->id, $studentrole->id, 'manual');
        $this->setUser($student);
        core_tag_index_builder::reset_caches();

        // User can not see posts in course 3 because he is not enrolled.
        $res = mod_digestforum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */1);
        $this->assertRegExp('/'.$post22->subject.'/', $res->content);
        $this->assertRegExp('/'.$post23->subject.'/', $res->content);
        $this->assertNotRegExp('/'.$post31->subject.'/', $res->content);

        // User can search digestforum posts inside a course.
        $coursecontext = context_course::instance($course1->id);
        $res = mod_digestforum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */$coursecontext->id, /*$rec = */1, /*$post = */0);
        $this->assertRegExp('/'.$post11->subject.'/', $res->content);
        $this->assertRegExp('/'.$post12->subject.'/', $res->content);
        $this->assertRegExp('/'.$post13->subject.'/', $res->content);
        $this->assertNotRegExp('/'.$post14->subject.'/', $res->content);
        $this->assertRegExp('/'.$post15->subject.'/', $res->content);
        $this->assertRegExp('/'.$post16->subject.'/', $res->content);
        $this->assertNotRegExp('/'.$post21->subject.'/', $res->content);
        $this->assertNotRegExp('/'.$post22->subject.'/', $res->content);
        $this->assertNotRegExp('/'.$post23->subject.'/', $res->content);
        $this->assertEmpty($res->nextpageurl);
    }

    /**
     * Creates an action event.
     *
     * @param int $courseid The course id.
     * @param int $instanceid The instance id.
     * @param string $eventtype The event type.
     * @return bool|calendar_event
     */
    private function create_action_event($courseid, $instanceid, $eventtype) {
        $event = new stdClass();
        $event->name = 'Calendar event';
        $event->modulename  = 'digestforum';
        $event->courseid = $courseid;
        $event->instance = $instanceid;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = $eventtype;
        $event->timestart = time();

        return calendar_event::create($event);
    }

    /**
     * Test the callback responsible for returning the completion rule descriptions.
     * This function should work given either an instance of the module (cm_info), such as when checking the active rules,
     * or if passed a stdClass of similar structure, such as when checking the the default completion settings for a mod type.
     */
    public function test_mod_digestforum_completion_get_active_rule_descriptions() {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two activities, both with automatic completion. One has the 'completionsubmit' rule, one doesn't.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 2]);
        $digestforum1 = $this->getDataGenerator()->create_module('digestforum', [
            'course' => $course->id,
            'completion' => 2,
            'completiondiscussions' => 3,
            'completionreplies' => 3,
            'completionposts' => 3
        ]);
        $digestforum2 = $this->getDataGenerator()->create_module('digestforum', [
            'course' => $course->id,
            'completion' => 2,
            'completiondiscussions' => 0,
            'completionreplies' => 0,
            'completionposts' => 0
        ]);
        $cm1 = cm_info::create(get_coursemodule_from_instance('digestforum', $digestforum1->id));
        $cm2 = cm_info::create(get_coursemodule_from_instance('digestforum', $digestforum2->id));

        // Data for the stdClass input type.
        // This type of input would occur when checking the default completion rules for an activity type, where we don't have
        // any access to cm_info, rather the input is a stdClass containing completion and customdata attributes, just like cm_info.
        $moddefaults = new stdClass();
        $moddefaults->customdata = ['customcompletionrules' => [
            'completiondiscussions' => 3,
            'completionreplies' => 3,
            'completionposts' => 3
        ]];
        $moddefaults->completion = 2;

        $activeruledescriptions = [
            get_string('completiondiscussionsdesc', 'digestforum', 3),
            get_string('completionrepliesdesc', 'digestforum', 3),
            get_string('completionpostsdesc', 'digestforum', 3)
        ];
        $this->assertEquals(mod_digestforum_get_completion_active_rule_descriptions($cm1), $activeruledescriptions);
        $this->assertEquals(mod_digestforum_get_completion_active_rule_descriptions($cm2), []);
        $this->assertEquals(mod_digestforum_get_completion_active_rule_descriptions($moddefaults), $activeruledescriptions);
        $this->assertEquals(mod_digestforum_get_completion_active_rule_descriptions(new stdClass()), []);
    }
}
