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

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__ . '/cron_trait.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * The module digestforums external functions unit tests
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2013 Andrew Nicols
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class maildigest_test extends \advanced_testcase {
    // Make use of the cron tester trait.
    use mod_digestforum_tests_cron_trait;

    // Make use of the test generator trait.
    use mod_digestforum_tests_generator_trait;

    /** @var \core\test\phpunit\message_sink */
    protected $messagesink;

    /** @var \core\test\phpunit\message_sink */
    protected $mailsink;

    /**
     * Set up message and mail sinks, and set up other requirements for the
     * cron to be tested here.
     */
    public function setUp(): void {
        global $CFG;
        parent::setUp();

        // Messaging is not compatible with transactions...
        $this->preventResetByRollback();

        // Catch all messages
        $this->messagesink = $this->redirectMessages();
        $this->mailsink = $this->redirectEmails();

        // Confirm that we have an empty message sink so far.
        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $this->assertEquals(0, count($messages));

        $messages = $this->mailsink->get_messages();
        $this->assertEquals(0, count($messages));

        // Tell Moodle that we've not sent any digest messages out recently.
        $CFG->digestforum_mailtimelast = 0;

        // And set the digest sending time to a negative number - this has
        // the effect of making it 11pm the previous day.
        $CFG->digestforum_mailtime = -1;

        // Forcibly reduce the maxeditingtime to a one second to ensure that
        // messages are sent out.
        $CFG->maxeditingtime = 1;

        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();
    }

    /**
     * Clear the message sinks set up in this test.
     */
    public function tearDown(): void {
        $this->messagesink->clear();
        $this->messagesink->close();

        $this->mailsink->clear();
        $this->mailsink->close();
        parent::tearDown();
    }

    /**
     * Setup a user, course, and digestforums.
     *
     * @return stdClass containing the list of digestforums, courses, digestforumids,
     * and the user enrolled in them.
     */
    protected function helper_setup_user_in_course() {
        global $DB;

        $return = new \stdClass();
        $return->courses = new \stdClass();
        $return->digestforums = new \stdClass();
        $return->digestforumids = [];

        // Create a user.
        $user = $this->getDataGenerator()->create_user();
        $return->user = $user;

        // Create courses to add the modules.
        $return->courses->course1 = $this->getDataGenerator()->create_course();

        // Create digestforums.
        $record = new \stdClass();
        $record->course = $return->courses->course1->id;
        $record->forcesubscribe = 1;

        $return->digestforums->digestforum1 = $this->getDataGenerator()->create_module('digestforum', $record);
        $return->digestforumsids[] = $return->digestforums->digestforum1->id;

        $return->digestforums->digestforum2 = $this->getDataGenerator()->create_module('digestforum', $record);
        $return->digestforumsids[] = $return->digestforums->digestforum2->id;

        // Check the digestforum was correctly created.
         [$test, $params] = $DB->get_in_or_equal($return->digestforumsids);

        // Enrol the user in the courses.
        // DataGenerator->enrol_user automatically sets a role for the user
        $this->getDataGenerator()->enrol_user($return->user->id, $return->courses->course1->id);

        return $return;
    }

    public function test_set_maildigest(): void {
        global $DB;

        $this->resetAfterTest(true);

        $helper = $this->helper_setup_user_in_course();
        $user = $helper->user;
        $course1 = $helper->courses->course1;
        $digestforum1 = $helper->digestforums->digestforum1;

        // Set to the user.
        self::setUser($helper->user);

        // Confirm that there is no current value.
        $currentsetting = $DB->get_record('digestforum_digests', [
            'digestforum' => $digestforum1->id,
            'userid' => $user->id,
        ]);
        $this->assertFalse($currentsetting);

        // Test with each of the valid values:
        // 0, 1, and 2 are valid values.
        digestforum_set_user_maildigest($digestforum1, 0, $user);
        $currentsetting = $DB->get_record('digestforum_digests', [
            'digestforum' => $digestforum1->id,
            'userid' => $user->id,
        ]);
        $this->assertEquals($currentsetting->maildigest, 0);

        digestforum_set_user_maildigest($digestforum1, 1, $user);
        $currentsetting = $DB->get_record('digestforum_digests', [
            'digestforum' => $digestforum1->id,
            'userid' => $user->id,
        ]);
        $this->assertEquals($currentsetting->maildigest, 1);

        digestforum_set_user_maildigest($digestforum1, 2, $user);
        $currentsetting = $DB->get_record('digestforum_digests', [
            'digestforum' => $digestforum1->id,
            'userid' => $user->id,
        ]);
        $this->assertEquals($currentsetting->maildigest, 2);

        // And the default value - this should delete the record again
        digestforum_set_user_maildigest($digestforum1, -1, $user);
        $currentsetting = $DB->get_record('digestforum_digests', [
            'digestforum' => $digestforum1->id,
            'userid' => $user->id,
        ]);
        $this->assertFalse($currentsetting);

        // Try with an invalid value.
        $this->expectException('moodle_exception');
        digestforum_set_user_maildigest($digestforum1, 42, $user);
    }

    public function test_get_user_digest_options_default(): void {
        global $USER, $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $helper = $this->helper_setup_user_in_course();
        $user = $helper->user;
        $course1 = $helper->courses->course1;
        $digestforum1 = $helper->digestforums->digestforum1;

        // Set to the user.
        self::setUser($helper->user);

        // We test against these options.
        $digestoptions = [
            '0' => get_string('emaildigestoffshort', 'mod_digestforum'),
            '1' => get_string('emaildigestcompleteshort', 'mod_digestforum'),
            '2' => get_string('emaildigestsubjectsshort', 'mod_digestforum'),
        ];

        // Everyone receives complete digests, so the default is always complete, whatever the user's setting.
        $this->assertEquals(0, $user->maildigest);
        foreach ([0, 1, 2] as $maildigest) {
            $USER->maildigest = $maildigest;
            $options = digestforum_get_user_digest_options();
            $this->assertEquals(get_string('emaildigestdefault', 'mod_digestforum', $digestoptions[1]), $options[-1]);
        }
    }

    public function test_get_user_digest_options_sorting(): void {
        global $USER, $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $helper = $this->helper_setup_user_in_course();
        $user = $helper->user;
        $course1 = $helper->courses->course1;
        $digestforum1 = $helper->digestforums->digestforum1;

        // Set to the user.
        self::setUser($helper->user);

        // Retrieve the list of applicable options.
        $options = digestforum_get_user_digest_options();

        // The default option must always be at the top of the list.
        $lastoption = -2;
        foreach ($options as $value => $description) {
            $this->assertGreaterThan($lastoption, $value);
            $lastoption = $value;
        }
    }

    public function test_cron_no_posts(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Initially the digestforum cron should generate no messages as we've made no posts.
        $expect = [];
        $this->queue_tasks_and_assert($expect);
    }

    /**
     * Everyone receives digests, even when their profile asks for single emails.
     */
    public function test_cron_profile_single_mails_sent_as_digests(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;
        $posts = [];

        // Add 5 discussions to each digestforum.
        for ($i = 0; $i < 5; $i++) {
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
            $posts[$digestforum1->id][] = $post;
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum2, $user, ['mailnow' => 1]);
            $posts[$digestforum2->id][] = $post;
        }

        // Ask for single emails in the profile, and use the default for each digestforum.
        $DB->set_field('user', 'maildigest', 0, ['id' => $user->id]);
        digestforum_set_user_maildigest($digestforum1, -1, $user);
        digestforum_set_user_maildigest($digestforum2, -1, $user);

        // One digest task is queued, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $this->send_notifications_and_assert($user, []);
        $this->send_digests_and_assert($user, $posts);
    }

    /**
     * Everyone receives complete digests, whatever their per-digestforum preferences.
     */
    public function test_cron_digestforum_preferences_ignored(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;
        $posts = [];

        // Add 5 discussions to each digestforum.
        for ($i = 0; $i < 5; $i++) {
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
            $posts[$digestforum1->id][] = $post;
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum2, $user, ['mailnow' => 1]);
            $posts[$digestforum2->id][] = $post;
        }

        // Ask for single emails in digestforum1, and short digests in digestforum2.
        $DB->set_field('user', 'maildigest', 0, ['id' => $user->id]);
        digestforum_set_user_maildigest($digestforum1, 0, $user);
        digestforum_set_user_maildigest($digestforum2, 2, $user);

        // One digest task is queued, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $this->send_notifications_and_assert($user, []);
        $this->send_digests_and_assert($user, $posts);
    }

    /**
     * Each digestforum is sent as its own email, containing only its own discussions.
     */
    public function test_cron_digest_email_per_digestforum(): void {
        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;

        [$discussion1, $post1] = $this->helper_post_to_digestforum(
            $digestforum1,
            $user,
            ['mailnow' => 1, 'name' => 'Discussion in forum one']
        );
        [$discussion2, $post2] = $this->helper_post_to_digestforum(
            $digestforum2,
            $user,
            ['mailnow' => 1, 'name' => 'Discussion in forum two']
        );

        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);
        $this->send_digests_and_assert($user, [
            $digestforum1->id => [$post1],
            $digestforum2->id => [$post2],
        ]);

        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $this->assertCount(2, $messages);

        $bysubject = [];
        foreach ($messages as $message) {
            $this->assertEquals('digests', $message->eventtype);
            $bysubject[$message->subject] = $message;
        }

        $date = userdate(time(), '%a %b %e, %Y');
        $subject1 = get_string('digestmailsubject', 'digestforum', (object) [
            'digestforumname' => $digestforum1->name,
            'date' => $date,
        ]);
        $subject2 = get_string('digestmailsubject', 'digestforum', (object) [
            'digestforumname' => $digestforum2->name,
            'date' => $date,
        ]);
        $this->assertArrayHasKey($subject1, $bysubject);
        $this->assertArrayHasKey($subject2, $bysubject);

        $this->assertStringContainsString('Discussion in forum one', $bysubject[$subject1]->fullmessage);
        $this->assertStringNotContainsString('Discussion in forum two', $bysubject[$subject1]->fullmessage);
        $this->assertStringContainsString('Discussion in forum two', $bysubject[$subject2]->fullmessage);
        $this->assertStringNotContainsString('Discussion in forum one', $bysubject[$subject2]->fullmessage);
    }

    /**
     * Each digest sent is recorded in the tracker, and HTML digests carry the tracking image.
     */
    public function test_cron_digest_tracker_html(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course, who receives HTML email.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $DB->set_field('user', 'mailformat', 1, ['id' => $user->id]);
        $digestforum1 = $userhelper->digestforums->digestforum1;

        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);

        $this->queue_tasks_and_assert([(object) ['userid' => $user->id, 'digests' => 1]]);
        $this->send_digests_and_assert($user, [$digestforum1->id => [$post]]);

        $trackers = $DB->get_records('digestforum_tracker');
        $this->assertCount(1, $trackers);
        $tracker = reset($trackers);
        $this->assertEquals($user->id, $tracker->mdluserid);
        $this->assertEquals($digestforum1->id, $tracker->digestforumid);
        $this->assertEquals(userdate(time(), '%Y-%m-%d'), $tracker->digestdate);
        $this->assertEquals(0, $tracker->numviews);

        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $imgurl = (new \moodle_url('/mod/digestforum/img.php', ['id' => $tracker->id]))->out(false);
        $this->assertStringContainsString($imgurl, $message->fullmessagehtml);
        $this->assertStringContainsString('digestforum-digest', $message->fullmessagehtml);

        // The queue has been emptied.
        $this->assertEquals(0, $DB->count_records('digestforum_queue', ['userid' => $user->id]));
    }

    /**
     * Plain text digests are still recorded in the tracker, but have no tracking image.
     */
    public function test_cron_digest_tracker_plaintext(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course, who receives plain text email.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $DB->set_field('user', 'mailformat', 0, ['id' => $user->id]);
        $digestforum1 = $userhelper->digestforums->digestforum1;

        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);

        $this->queue_tasks_and_assert([(object) ['userid' => $user->id, 'digests' => 1]]);
        $this->send_digests_and_assert($user, [$digestforum1->id => [$post]]);

        $this->assertEquals(1, $DB->count_records('digestforum_tracker', ['mdluserid' => $user->id]));

        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $message = reset($messages);
        $this->assertStringNotContainsString('img.php', $message->fullmessagehtml);
    }

    /**
     * The digest header shows the open rate for this month once there is tracking data.
     */
    public function test_cron_digest_header_open_rate(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;

        // Earlier digests this month: one opened, one not, for digestforum1 only.
        $today = userdate(time(), '%Y-%m-%d');
        foreach ([1, 0] as $numviews) {
            $DB->insert_record('digestforum_tracker', (object) [
                'mdluserid' => $user->id,
                'digestforumid' => $digestforum1->id,
                'digestdate' => $today,
                'numviews' => $numviews,
            ]);
        }

        [$discussion1, $post1] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        [$discussion2, $post2] = $this->helper_post_to_digestforum($digestforum2, $user, ['mailnow' => 1]);

        $this->queue_tasks_and_assert([(object) ['userid' => $user->id, 'digests' => 1]]);
        $this->send_digests_and_assert($user, [
            $digestforum1->id => [$post1],
            $digestforum2->id => [$post2],
        ]);

        $stat = get_string('digestmailheaderstat', 'digestforum', (object) ['openrate' => 50]);
        $withstat = 0;
        foreach ($this->messagesink->get_messages_by_component('mod_digestforum') as $message) {
            if (strpos($message->subject, $digestforum1->name) !== false) {
                $this->assertStringContainsString($stat, $message->fullmessage);
                $withstat++;
            } else {
                // There is no tracking data for digestforum2 yet, so no open rate is shown.
                $this->assertStringNotContainsString('open rate', $message->fullmessage);
            }
        }
        $this->assertEquals(1, $withstat);
    }

    /**
     * A digest that fails to send is removed from the tracker, but the queue is still emptied so that the
     * digests which did send are not sent again.
     */
    public function test_cron_digest_send_failure(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;

        $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        $this->queue_tasks_and_assert([(object) ['userid' => $user->id, 'digests' => 1]]);
        $this->assertGreaterThan(0, $DB->count_records('digestforum_queue', ['userid' => $user->id]));

        $task = new class extends \mod_digestforum\task\send_user_digests {
            protected function send_mail(string $messageid) {
                return false;
            }
        };
        $task->set_userid($user->id);
        $task->set_custom_data(['servermidnight' => usergetmidnight(time(), \core_date::get_server_timezone())]);

        ob_start();
        \core\cron::setup_user($user);
        $task->execute();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression("/Issue sending digest for digestforum {$digestforum1->id}/", $output);
        $this->assertMatchesRegularExpression("/Sent 0 digest\(s\)./", $output);
        $this->assertEquals(0, $DB->count_records('digestforum_tracker'));
        $this->assertEquals(0, $DB->count_records('digestforum_queue', ['userid' => $user->id]));
    }

    /**
     * Send digests to a user who cannot view fullnames
     */
    public function test_cron_digest_view_fullnames_off(): void {
        global $DB, $CFG;

        $CFG->fullnamedisplay = 'lastname';
        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $posts = [];

        // Add 1 discussions to digestforum 1.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        $posts[] = $post;

        // Set the tested user's default maildigest setting.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);

        // No digests mails should be sent, but 1 digestforum mails will be sent.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);
        $this->send_digests_and_assert($user, [$digestforum1->id => $posts]);

        // The user does not, by default, have permission to view the fullname.
        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $messages = reset($messages);
        $messagecontent = $messages->fullmessage;

        // Assert that the expected name is present (lastname only).
        $this->assertStringContainsString(fullname($user, false), $messagecontent);

        // Assert that the full name is not present (firstname lastname only).
        $this->assertStringNotContainsString(fullname($user, true), $messagecontent);
    }

    /**
     * Send digests to a user who can view fullnames.
     */
    public function test_cron_digest_view_fullnames_on(): void {
        global $DB, $CFG;

        $CFG->fullnamedisplay = 'lastname';
        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $posts = [];
        assign_capability(
            'moodle/site:viewfullnames',
            CAP_ALLOW,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            \context_course::instance($course1->id)
        );

        // Add 1 discussions to digestforum 1.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        $posts[] = $post;

        // Set the tested user's default maildigest setting.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);

        // No digests mails should be sent, but 1 digestforum mails will be sent.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);
        $this->send_digests_and_assert($user, [$digestforum1->id => $posts]);

        // The user does not, by default, have permission to view the fullname.
        // However we have given the user that capability so we expect to see both firstname and lastname.
        $messages = $this->messagesink->get_messages_by_component('mod_digestforum');
        $messages = reset($messages);
        $messagecontent = $messages->fullmessage;

        // Assert that the expected name is present (lastname only).
        $this->assertStringContainsString(fullname($user, false), $messagecontent);

        // Assert that the full name is also present (firstname lastname only).
        $this->assertStringContainsString(fullname($user, true), $messagecontent);
    }

    /**
     * The digest being in the past is queued til the next day.
     */
    public function test_cron_digest_previous_day(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;
        $fulldigests = [];
        $shortdigests = [];

        // Add 1 discussions to digestforum 1.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        $fulldigests[] = $post;

        // Set the tested user's default maildigest setting.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);

        // Set the digest time to midnight.
        $CFG->digestforum_mailtime = 0;
        // One digest e-mail should be sent, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_digestforum']);
        $task = reset($tasks);
        $this->assertGreaterThanOrEqual(time(), $task->nextruntime);
    }

    /**
     * The digest being in the future is queued for today.
     */
    public function test_cron_digest_same_day(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $digestforum2 = $userhelper->digestforums->digestforum2;
        $fulldigests = [];
        $shortdigests = [];

        // Add 1 discussions to digestforum 1.
        [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
        $fulldigests[] = $post;

        // Set the tested user's default maildigest setting.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);

        // Set the digest time to the future (magic, shouldn't work).
        $CFG->digestforum_mailtime = 25;
        // One digest e-mail should be sent, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_digestforum']);
        $task = reset($tasks);
        $digesttime = usergetmidnight(time(), \core_date::get_server_timezone()) + ($CFG->digestforum_mailtime * 3600);
        $this->assertLessThanOrEqual($digesttime, $task->nextruntime);
    }

    /**
     * Tests that if a new message is posted after the days digest time,
     * but before that days digests are sent a new task is created.
     */
    public function test_cron_digest_queue_next_before_current_processed(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $digestforum1 = $userhelper->digestforums->digestforum1;

        // Add 1 discussions to digestforum 1.
        $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);

        // Set the tested user's default maildigest setting.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);

        // Set the digest time to the future (magic, shouldn't work).
        $CFG->digestforum_mailtime = 25;
        // One digest e-mail should be sent, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        // Set the digest time to midnight.
        $CFG->digestforum_mailtime = 0;

        // Add another discussions to digestforum 1.
        $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);

        // One digest e-mail should be sent, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        // There should now be two tasks queued.
        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_digestforum']);
        $this->assertCount(2, $tasks);

        // Add yet another another discussions to digestforum 1.
        $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);

        // One digest e-mail should be sent, and no individual notifications.
        $expect = [
            (object) [
                'userid' => $user->id,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        // There should still be two tasks queued.
        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_digestforum']);
        $this->assertCount(2, $tasks);
    }

    /**
     * The sending of a digest marks posts as read if automatic message read marking is set.
     */
    public function test_cron_digest_marks_posts_read(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Disable the 'Manual message read marking' option.
        $CFG->digestforum_usermarksread = false;

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $posts = [];

        // Set the tested user's default maildigest, trackforums, read tracking settings.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);
        $DB->set_field('user', 'trackforums', 1, ['id' => $user->id]);
        set_user_preference('digestforum_markasreadonnotification', 1, $user->id);

        // Set the maildigest preference for digestforum1 to default.
        digestforum_set_user_maildigest($digestforum1, -1, $user);

        // Add 5 discussions to digestforum 1.
        for ($i = 0; $i < 5; $i++) {
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
            $posts[] = $post;
        }

        // There should be unread posts for the digestforum.
        $expectedposts = [
            $digestforum1->id => (object) [
                'id' => $digestforum1->id,
                'unread' => count($posts),
            ],
        ];
        $this->assertEquals($expectedposts, digestforum_tp_get_course_unread_posts($user->id, $course1->id));

        // One digest mail should be sent and no other messages.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $this->send_digests_and_assert($user, [$digestforum1->id => $posts]);

        // Verify that there are no unread posts for any digestforums.
        $this->assertEmpty(digestforum_tp_get_course_unread_posts($user->id, $course1->id));
    }

    /**
     * The sending of a digest does not mark posts as read when manual message read marking is set.
     */
    public function test_cron_digest_leaves_posts_unread(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Enable the 'Manual message read marking' option.
        $CFG->digestforum_usermarksread = true;

        // Set up a basic user enrolled in a course.
        $userhelper = $this->helper_setup_user_in_course();
        $user = $userhelper->user;
        $course1 = $userhelper->courses->course1;
        $digestforum1 = $userhelper->digestforums->digestforum1;
        $posts = [];

        // Set the tested user's default maildigest, trackforums, read tracking settings.
        $DB->set_field('user', 'maildigest', 1, ['id' => $user->id]);
        $DB->set_field('user', 'trackforums', 1, ['id' => $user->id]);
        set_user_preference('digestforum_markasreadonnotification', 1, $user->id);

        // Set the maildigest preference for digestforum1 to default.
        digestforum_set_user_maildigest($digestforum1, -1, $user);

        // Add 5 discussions to digestforum 1.
        for ($i = 0; $i < 5; $i++) {
            [$discussion, $post] = $this->helper_post_to_digestforum($digestforum1, $user, ['mailnow' => 1]);
            $posts[] = $post;
        }

        // There should be unread posts for the digestforum.
        $expectedposts = [
            $digestforum1->id => (object) [
                'id' => $digestforum1->id,
                'unread' => count($posts),
            ],
        ];
        $this->assertEquals($expectedposts, digestforum_tp_get_course_unread_posts($user->id, $course1->id));

        // One digest mail should be sent and no other messages.
        $expect = [
            (object) [
                'userid' => $user->id,
                'messages' => 0,
                'digests' => 1,
            ],
        ];
        $this->queue_tasks_and_assert($expect);

        $this->send_digests_and_assert($user, [$digestforum1->id => $posts]);

        // Verify that there are still the same unread posts for the digestforum.
        $this->assertEquals($expectedposts, digestforum_tp_get_course_unread_posts($user->id, $course1->id));
    }
}
