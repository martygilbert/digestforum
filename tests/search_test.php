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
 * Forum search unit tests.
 *
 * @package     mod_digestforum
 * @category    test
 * @copyright   2015 David Monllao {@link http://www.davidmonllao.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/search/tests/fixtures/testable_core_search.php');
require_once($CFG->dirroot . '/mod/digestforum/tests/generator/lib.php');
require_once($CFG->dirroot . '/mod/digestforum/lib.php');

/**
 * Provides the unit tests for digestforum search.
 *
 * @package     mod_digestforum
 * @category    test
 * @copyright   2015 David Monllao {@link http://www.davidmonllao.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_digestforum_search_testcase extends advanced_testcase {

    /**
     * @var string Area id
     */
    protected $digestforumpostareaid = null;

    public function setUp() {
        $this->resetAfterTest(true);
        set_config('enableglobalsearch', true);

        $this->digestforumpostareaid = \core_search\manager::generate_areaid('mod_digestforum', 'post');

        // Set \core_search::instance to the mock_search_engine as we don't require the search engine to be working to test this.
        $search = testable_core_search::instance();
    }

    /**
     * Availability.
     *
     * @return void
     */
    public function test_search_enabled() {

        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        list($componentname, $varname) = $searcharea->get_config_var_name();

        // Enabled by default once global search is enabled.
        $this->assertTrue($searcharea->is_enabled());

        set_config($varname . '_enabled', 0, $componentname);
        $this->assertFalse($searcharea->is_enabled());

        set_config($varname . '_enabled', 1, $componentname);
        $this->assertTrue($searcharea->is_enabled());
    }

    /**
     * Indexing mod digestforum contents.
     *
     * @return void
     */
    public function test_posts_indexing() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $this->assertInstanceOf('\mod_digestforum\search\post', $searcharea);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        // Available for both student and teacher.
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', $record);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->message = 'discussion';
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post2';
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // All records.
        $recordset = $searcharea->get_recordset_by_timestamp(0);
        $this->assertTrue($recordset->valid());
        $nrecords = 0;
        foreach ($recordset as $record) {
            $this->assertInstanceOf('stdClass', $record);
            $doc = $searcharea->get_document($record);
            $this->assertInstanceOf('\core_search\document', $doc);

            // Static caches are working.
            $dbreads = $DB->perf_get_reads();
            $doc = $searcharea->get_document($record);
            $this->assertEquals($dbreads, $DB->perf_get_reads());
            $this->assertInstanceOf('\core_search\document', $doc);
            $nrecords++;
        }
        // If there would be an error/failure in the foreach above the recordset would be closed on shutdown.
        $recordset->close();
        $this->assertEquals(2, $nrecords);

        // The +2 is to prevent race conditions.
        $recordset = $searcharea->get_recordset_by_timestamp(time() + 2);

        // No new records.
        $this->assertFalse($recordset->valid());
        $recordset->close();

        // Context test: create another digestforum with 1 post.
        $digestforum2 = self::getDataGenerator()->create_module('digestforum', ['course' => $course1->id]);
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum2->id;
        $record->message = 'discussion';
        self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Test indexing with each digestforum then combined course context.
        $rs = $searcharea->get_document_recordset(0, context_module::instance($digestforum1->cmid));
        $this->assertEquals(2, iterator_count($rs));
        $rs->close();
        $rs = $searcharea->get_document_recordset(0, context_module::instance($digestforum2->cmid));
        $this->assertEquals(1, iterator_count($rs));
        $rs->close();
        $rs = $searcharea->get_document_recordset(0, context_course::instance($course1->id));
        $this->assertEquals(3, iterator_count($rs));
        $rs->close();
    }

    /**
     * Document contents.
     *
     * @return void
     */
    public function test_posts_document() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $this->assertInstanceOf('\mod_digestforum\search\post', $searcharea);

        $user = self::getDataGenerator()->create_user();
        $course1 = self::getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'teacher');

        $record = new stdClass();
        $record->course = $course1->id;
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', $record);

        // Teacher only.
        $digestforum2 = self::getDataGenerator()->create_module('digestforum', $record);
        set_coursemodule_visible($digestforum2->cmid, 0);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user->id;
        $record->digestforum = $digestforum1->id;
        $record->message = 'discussion';
        $record->groupid = 0;
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user->id;
        $record->subject = 'subject1';
        $record->message = 'post1';
        $record->groupid = -1;
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $post1 = $DB->get_record('digestforum_posts', array('id' => $discussion1reply1->id));
        $post1->digestforumid = $digestforum1->id;
        $post1->courseid = $digestforum1->course;
        $post1->groupid = -1;

        $doc = $searcharea->get_document($post1);
        $this->assertInstanceOf('\core_search\document', $doc);
        $this->assertEquals($discussion1reply1->id, $doc->get('itemid'));
        $this->assertEquals($this->digestforumpostareaid . '-' . $discussion1reply1->id, $doc->get('id'));
        $this->assertEquals($course1->id, $doc->get('courseid'));
        $this->assertEquals($user->id, $doc->get('userid'));
        $this->assertEquals($discussion1reply1->subject, $doc->get('title'));
        $this->assertEquals($discussion1reply1->message, $doc->get('content'));
    }

    /**
     * Group support for digestforum posts.
     */
    public function test_posts_group_support() {
        // Get the search area and test generators.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $generator = $this->getDataGenerator();
        $digestforumgenerator = $generator->get_plugin_generator('mod_digestforum');

        // Create a course, a user, and two groups.
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'teacher');
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Separate groups digestforum.
        $digestforum = self::getDataGenerator()->create_module('digestforum', ['course' => $course->id,
                'groupmode' => SEPARATEGROUPS]);

        // Create discussion with each group and one for all groups. One has a post in.
        $discussion1 = $digestforumgenerator->create_discussion(['course' => $course->id,
                'userid' => $user->id, 'digestforum' => $digestforum->id, 'message' => 'd1',
                'groupid' => $group1->id]);
        $digestforumgenerator->create_discussion(['course' => $course->id,
                'userid' => $user->id, 'digestforum' => $digestforum->id, 'message' => 'd2',
                'groupid' => $group2->id]);
        $digestforumgenerator->create_discussion(['course' => $course->id,
                'userid' => $user->id, 'digestforum' => $digestforum->id, 'message' => 'd3']);

        // Create a reply in discussion1.
        $digestforumgenerator->create_post(['discussion' => $discussion1->id, 'parent' => $discussion1->firstpost,
                'userid' => $user->id, 'message' => 'p1']);

        // Do the indexing of all 4 posts.
        $rs = $searcharea->get_recordset_by_timestamp(0);
        $results = [];
        foreach ($rs as $rec) {
            $results[$rec->message] = $rec;
        }
        $rs->close();
        $this->assertCount(4, $results);

        // Check each document has the correct groupid.
        $doc = $searcharea->get_document($results['d1']);
        $this->assertTrue($doc->is_set('groupid'));
        $this->assertEquals($group1->id, $doc->get('groupid'));
        $doc = $searcharea->get_document($results['d2']);
        $this->assertTrue($doc->is_set('groupid'));
        $this->assertEquals($group2->id, $doc->get('groupid'));
        $doc = $searcharea->get_document($results['d3']);
        $this->assertFalse($doc->is_set('groupid'));
        $doc = $searcharea->get_document($results['p1']);
        $this->assertTrue($doc->is_set('groupid'));
        $this->assertEquals($group1->id, $doc->get('groupid'));

        // While we're here, also test that the search area requests restriction by group.
        $modinfo = get_fast_modinfo($course);
        $this->assertTrue($searcharea->restrict_cm_access_by_group($modinfo->get_cm($digestforum->cmid)));

        // In visible groups mode, it won't request restriction by group.
        set_coursemodule_groupmode($digestforum->cmid, VISIBLEGROUPS);
        $modinfo = get_fast_modinfo($course);
        $this->assertFalse($searcharea->restrict_cm_access_by_group($modinfo->get_cm($digestforum->cmid)));
    }

    /**
     * Document accesses.
     *
     * @return void
     */
    public function test_posts_access() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'teacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        // Available for both student and teacher.
        $digestforum1 = self::getDataGenerator()->create_module('digestforum', $record);

        // Teacher only.
        $digestforum2 = self::getDataGenerator()->create_module('digestforum', $record);
        set_coursemodule_visible($digestforum2->cmid, 0);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->message = 'discussion';
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post1';
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Create discussion2 only visible to teacher.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum2->id;
        $record->message = 'discussion';
        $discussion2 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Create post2 in discussion2.
        $record = new stdClass();
        $record->discussion = $discussion2->id;
        $record->parent = $discussion2->firstpost;
        $record->userid = $user1->id;
        $record->message = 'post2';
        $discussion2reply1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $this->setUser($user2);
        $this->assertEquals(\core_search\manager::ACCESS_GRANTED, $searcharea->check_access($discussion1reply1->id));
        $this->assertEquals(\core_search\manager::ACCESS_DENIED, $searcharea->check_access($discussion2reply1->id));
    }

    /**
     * Test for post attachments.
     *
     * @return void
     */
    public function test_attach_files() {
        global $DB;

        $fs = get_file_storage();

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $this->assertInstanceOf('\mod_digestforum\search\post', $searcharea);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        $digestforum1 = self::getDataGenerator()->create_module('digestforum', $record);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->digestforum = $digestforum1->id;
        $record->message = 'discussion';
        $record->attachemt = 1;
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_discussion($record);

        // Attach 2 file to the discussion post.
        $post = $DB->get_record('digestforum_posts', array('discussion' => $discussion1->id));
        $filerecord = array(
            'contextid' => context_module::instance($digestforum1->cmid)->id,
            'component' => 'mod_digestforum',
            'filearea'  => 'attachment',
            'itemid'    => $post->id,
            'filepath'  => '/',
            'filename'  => 'myfile1'
        );
        $file1 = $fs->create_file_from_string($filerecord, 'Some contents 1');
        $filerecord['filename'] = 'myfile2';
        $file2 = $fs->create_file_from_string($filerecord, 'Some contents 2');

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post2';
        $record->attachemt = 1;
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        $filerecord['itemid'] = $discussion1reply1->id;
        $filerecord['filename'] = 'myfile3';
        $file3 = $fs->create_file_from_string($filerecord, 'Some contents 3');

        // Create post2 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post3';
        $discussion1reply2 = self::getDataGenerator()->get_plugin_generator('mod_digestforum')->create_post($record);

        // Now get all the posts and see if they have the right files attached.
        $searcharea = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $recordset = $searcharea->get_recordset_by_timestamp(0);
        $nrecords = 0;
        foreach ($recordset as $record) {
            $doc = $searcharea->get_document($record);
            $searcharea->attach_files($doc);
            $files = $doc->get_files();
            // Now check that each doc has the right files on it.
            switch ($doc->get('itemid')) {
                case ($post->id):
                    $this->assertCount(2, $files);
                    $this->assertEquals($file1->get_id(), $files[$file1->get_id()]->get_id());
                    $this->assertEquals($file2->get_id(), $files[$file2->get_id()]->get_id());
                    break;
                case ($discussion1reply1->id):
                    $this->assertCount(1, $files);
                    $this->assertEquals($file3->get_id(), $files[$file3->get_id()]->get_id());
                    break;
                case ($discussion1reply2->id):
                    $this->assertCount(0, $files);
                    break;
                default:
                    $this->fail('Unexpected post returned');
                    break;
            }
            $nrecords++;
        }
        $recordset->close();
        $this->assertEquals(3, $nrecords);
    }

    /**
     * Tests that reindexing works in order starting from the digestforum with most recent discussion.
     */
    public function test_posts_get_contexts_to_reindex() {
        global $DB;

        $generator = $this->getDataGenerator();
        $adminuser = get_admin();

        $course1 = $generator->create_course();
        $course2 = $generator->create_course();

        $time = time() - 1000;

        // Create 3 digestforums (two in course 1, one in course 2 - doesn't make a difference).
        $digestforum1 = $generator->create_module('digestforum', ['course' => $course1->id]);
        $digestforum2 = $generator->create_module('digestforum', ['course' => $course1->id]);
        $digestforum3 = $generator->create_module('digestforum', ['course' => $course2->id]);
        $digestforum4 = $generator->create_module('digestforum', ['course' => $course2->id]);

        // Hack added time for the course_modules entries. These should not be used (they would
        // be used by the base class implementation). We are setting this so that the order would
        // be 4, 3, 2, 1 if this ordering were used (newest first).
        $DB->set_field('course_modules', 'added', $time + 100, ['id' => $digestforum1->cmid]);
        $DB->set_field('course_modules', 'added', $time + 110, ['id' => $digestforum2->cmid]);
        $DB->set_field('course_modules', 'added', $time + 120, ['id' => $digestforum3->cmid]);
        $DB->set_field('course_modules', 'added', $time + 130, ['id' => $digestforum4->cmid]);

        $digestforumgenerator = $generator->get_plugin_generator('mod_digestforum');

        // Create one discussion in digestforums 1 and 3, three in digestforum 2, and none in digestforum 4.
        $digestforumgenerator->create_discussion(['course' => $course1->id,
                'digestforum' => $digestforum1->id, 'userid' => $adminuser->id, 'timemodified' => $time + 20]);

        $digestforumgenerator->create_discussion(['course' => $course1->id,
                'digestforum' => $digestforum2->id, 'userid' => $adminuser->id, 'timemodified' => $time + 10]);
        $digestforumgenerator->create_discussion(['course' => $course1->id,
                'digestforum' => $digestforum2->id, 'userid' => $adminuser->id, 'timemodified' => $time + 30]);
        $digestforumgenerator->create_discussion(['course' => $course1->id,
                'digestforum' => $digestforum2->id, 'userid' => $adminuser->id, 'timemodified' => $time + 11]);

        $digestforumgenerator->create_discussion(['course' => $course2->id,
                'digestforum' => $digestforum3->id, 'userid' => $adminuser->id, 'timemodified' => $time + 25]);

        // Get the contexts in reindex order.
        $area = \core_search\manager::get_search_area($this->digestforumpostareaid);
        $contexts = iterator_to_array($area->get_contexts_to_reindex(), false);

        // We expect them in order of newest discussion. Forum 4 is not included at all (which is
        // correct because it has no content).
        $expected = [
            \context_module::instance($digestforum2->cmid),
            \context_module::instance($digestforum3->cmid),
            \context_module::instance($digestforum1->cmid)
        ];
        $this->assertEquals($expected, $contexts);
    }
}
