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

namespace mod_digestforum\output;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../generator_trait.php');

/**
 * Tests for the Moodle app screens.
 *
 * @package    mod_digestforum
 * @copyright  2026 Marty Gilbert <martygilbert@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mobile::class)]
final class mobile_test extends advanced_testcase {
    // Include the mod_digestforum test helpers.
    use \mod_digestforum_tests_generator_trait;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/digestforum/lib.php');
        parent::setUpBeforeClass();
    }

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The discussion list includes each discussion, and lets a student start a discussion.
     */
    public function test_mobile_course_view(): void {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id]);
        [$author, $student] = $this->helper_create_users($course, 2);

        $this->helper_post_to_digestforum($digestforum, $author, ['name' => 'First discussion']);
        [$discussion] = $this->helper_post_to_digestforum($digestforum, $author, ['name' => '{{ 7 * 7 }} discussion']);

        $this->setUser($student);
        $result = mobile::mobile_course_view(['cmid' => $digestforum->cmid, 'courseid' => $course->id]);

        $html = $result['templates'][0]['html'];
        $discussions = json_decode($result['otherdata']['discussions']);

        $this->assertCount(2, $discussions);
        $names = array_column($discussions, 'name');
        $this->assertContains('First discussion', $names);
        $this->assertContains('{{ 7 * 7 }} discussion', $names);
        $this->assertContains((int) $discussion->id, array_column($discussions, 'id'));
        $this->assertEquals(fullname($author), $discussions[0]->author);

        // Discussion names are only in otherdata, so the app never compiles them as part of the template.
        $this->assertStringNotContainsString('7 * 7', $html);
        $this->assertStringNotContainsString('First discussion', $html);

        // A student can start a discussion, and there is only one page.
        $this->assertStringContainsString('mod_digestforum_add_discussion', $html);
        $this->assertStringNotContainsString('page: ', $html);
    }

    /**
     * Users who cannot start a discussion are not shown the form.
     */
    public function test_mobile_course_view_cannot_add(): void {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id, 'type' => 'news']);
        [$student] = $this->helper_create_users($course, 1);

        $this->setUser($student);
        $result = mobile::mobile_course_view(['cmid' => $digestforum->cmid, 'courseid' => $course->id]);

        $this->assertStringNotContainsString('mod_digestforum_add_discussion', $result['templates'][0]['html']);
        $this->assertEquals([], json_decode($result['otherdata']['discussions']));
    }

    /**
     * Long discussion lists are split into pages, with buttons to move between them.
     */
    public function test_mobile_course_view_paging(): void {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id]);
        [$author] = $this->helper_create_users($course, 1);

        for ($i = 0; $i < mobile::DISCUSSIONS_PER_PAGE + 2; $i++) {
            $this->helper_post_to_digestforum($digestforum, $author);
        }

        $this->setUser($author);

        // The first page links to older discussions only.
        $result = mobile::mobile_course_view(['cmid' => $digestforum->cmid, 'courseid' => $course->id]);
        $html = $result['templates'][0]['html'];
        $this->assertCount(mobile::DISCUSSIONS_PER_PAGE, json_decode($result['otherdata']['discussions']));
        $this->assertStringContainsString('page: 1}', $html);
        $this->assertStringNotContainsString('page: 0}', $html);

        // The second page has the rest, and links back to newer discussions only. Posting is done from the first page.
        $result = mobile::mobile_course_view(['cmid' => $digestforum->cmid, 'courseid' => $course->id, 'page' => 1]);
        $html = $result['templates'][0]['html'];
        $this->assertCount(2, json_decode($result['otherdata']['discussions']));
        $this->assertStringContainsString('page: 0}', $html);
        $this->assertStringNotContainsString('page: 2}', $html);
        $this->assertStringNotContainsString('mod_digestforum_add_discussion', $html);
        $this->assertSame('', $result['otherdata']['intro']);
    }

    /**
     * A discussion shows its posts oldest first, each with a reply form.
     */
    public function test_mobile_discussion_view(): void {
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id]);
        [$author, $student] = $this->helper_create_users($course, 2);

        // The first post's subject is the discussion name.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum, $author, [
            'name' => 'Original subject',
            'message' => '<p>{{ 6 * 7 }} original message</p>',
        ]);
        $this->helper_update_post_time($post, -10);
        $reply = $this->helper_reply_to_post($post, $student, ['message' => '<p>The reply</p>']);

        $this->setUser($student);
        $result = mobile::mobile_discussion_view([
            'cmid' => $digestforum->cmid,
            'courseid' => $course->id,
            'discussionid' => $discussion->id,
        ]);

        $html = $result['templates'][0]['html'];
        $posts = json_decode($result['otherdata']['posts']);

        $this->assertCount(2, $posts);
        $this->assertEquals($post->id, $posts[0]->id);
        $this->assertEquals($reply->id, $posts[1]->id);
        $this->assertEquals('Original subject', $posts[0]->subject);
        $this->assertStringContainsString('original message', $posts[0]->message);
        $this->assertStringContainsString('Re: Original subject', $posts[0]->replysubject);
        $this->assertEquals(fullname($author), $posts[0]->author);
        $this->assertTrue($posts[0]->canreply);

        // Post content is only in otherdata, so the app never compiles it as part of the template.
        $this->assertStringNotContainsString('6 * 7', $html);
        $this->assertStringContainsString('mod_digestforum_add_discussion_post', $html);
    }
}
