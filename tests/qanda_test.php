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

namespace mod_digestforum;

use mod_digestforum_tests_cron_trait;
use mod_digestforum_tests_generator_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/digestforum/lib.php');
require_once(__DIR__ . '/cron_trait.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * The digestforum module mail generation tests for groups.
 *
 * @package    mod_digestforum
 * @copyright  2013 Andrew Nicols
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_digestforum\task\cron_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_digestforum\task\send_user_digests::class)]
final class qanda_test extends \advanced_testcase {
    // Make use of the cron tester trait.
    use mod_digestforum_tests_cron_trait;

    // Make use of the test generator trait.
    use mod_digestforum_tests_generator_trait;

    /**
     * @var \core\test\phpunit\message_sink
     */
    protected $messagesink;

    /**
     * @var \phpunit_mailer_sink
     */
    protected $mailsink;

    public function setUp(): void {
        global $CFG;
        parent::setUp();

        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();

        // Messaging is not compatible with transactions...
        $this->preventResetByRollback();

        // Catch all messages.
        $this->messagesink = $this->redirectMessages();
        $this->mailsink = $this->redirectEmails();

        // Forcibly reduce the maxeditingtime to a second in the past to
        // ensure that messages are sent out.
        $CFG->maxeditingtime = -1;

        // Send digests straight away.
        $CFG->digestforum_mailtimelast = 0;
        $CFG->digestforum_mailtime = -1;
    }

    public function tearDown(): void {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();

        $this->messagesink->clear();
        $this->messagesink->close();
        unset($this->messagesink);

        $this->mailsink->clear();
        $this->mailsink->close();
        unset($this->mailsink);
        parent::tearDown();
    }

    /**
     * Test that a user who has not posted in a q&a digestforum does not receive
     * notificatinos.
     */
    public function test_user_has_not_posted(): void {
        global $CFG, $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $digestforum = $this->getDataGenerator()->create_module('digestforum', [
            'course' => $course->id,
            'forcesubscribe' => DFORUM_INITIALSUBSCRIBE,
            'groupmode' => SEPARATEGROUPS,
            'type' => 'qanda',
        ]);

        // Create three students:
        // - author, enrolled in group A; and
        // - recipient, enrolled in group B; and
        // - other, enrolled in the course, but no groups.
        [$author, $recipient, $otheruser] = $this->helper_create_users($course, 3);

        // Create one editing teacher, not in any group but with accessallgroups capability.
        [$editingteacher] = $this->helper_create_users($course, 1, 'editingteacher');

        // Post a discussion to the digestforum.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum, $editingteacher);
        $reply = $this->helper_reply_to_post($post, $author);
        $otherreply = $this->helper_reply_to_post($post, $recipient);
        $DB->execute("UPDATE {digestforum_posts} SET modified = modified - 1");
        $DB->execute("UPDATE {digestforum_posts} SET created = created - 1");
        $DB->execute("UPDATE {digestforum_discussions} SET timemodified = timemodified - 1");

        // Only the author, recipient, and teachers should receive.
        $expect = [
            'author' => (object) [
                'userid' => $author->id,
                'digests' => 1,
            ],
            'recipient' => (object) [
                'userid' => $recipient->id,
                'digests' => 1,
            ],
            'otheruser' => (object) [
                'userid' => $otheruser->id,
                'digests' => 1,
            ],
            'editingteacher' => (object) [
                'userid' => $editingteacher->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);
        $posts = [$post, $reply, $otherreply];

        // No notifications should be queued.
        $this->send_digest_posts_and_assert($author, $posts);
        $this->send_digest_posts_and_assert($recipient, $posts);
        $this->send_digest_posts_and_assert($otheruser, [$post]);
        $this->send_digest_posts_and_assert($editingteacher, $posts);
    }
}
