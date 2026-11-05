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

namespace mod_digestforum\message\inbound;

use advanced_testcase;
use core\context\module;
use core\message\inbound\{manager, processing_failed_exception};
use mod_digestforum_generator;
use stdClass;
use Throwable;

/**
 * Unit tests for the reply handler
 *
 * @package     mod_digestforum
 * @copyright   2024 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_digestforum\message\inbound\reply_handler::class)]
final class reply_handler_test extends advanced_testcase {

    /**
     * Test attachment processing
     */
    public function test_process_message_attachments(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 1024]);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id, 'maxbytes' => 0]);

        $user = $this->getDataGenerator()->create_and_enrol($course);
        $this->setUser($user);

        [$discussion, $post] = $this->create_discussion_with_post($digestforum, $user);

        $output = $this->reply_handler_process_message($post, 'My reply', 'Hello', (object) [
            'filename' => 'Foo.txt',
            'content' => 'Foo',
            'filesize' => 3,
        ]);

        $this->assertMatchesRegularExpression('/Processing Foo.txt as an attachment./', $output);
        $this->assertMatchesRegularExpression('/Attaching Foo.txt to/', $output);
        $this->assertMatchesRegularExpression('/Created a post \d+ in \d+./', $output);

        // Assert our reply was added with attachment.
        $newpost = $DB->get_record('digestforum_posts', [
            'discussion' => $discussion->id,
            'subject' => 'My reply',
            'attachment' => 1,
        ], '*', MUST_EXIST);

        $attachments = get_file_storage()->get_area_files(
            module::instance($digestforum->cmid)->id,
            'mod_digestforum',
            'attachment',
            $newpost->id,
            'id',
            false,
        );
        $this->assertCount(1, $attachments);
        $this->assertEquals('Foo.txt', reset($attachments)->get_filename());
    }

    /**
     * Test attachment processing where maxbytes is exceeded
     */
    public function test_process_message_attachments_over_maxbytes(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id, 'maxbytes' => 2]);

        $user = $this->getDataGenerator()->create_and_enrol($course);
        $this->setUser($user);

        [$discussion, $post] = $this->create_discussion_with_post($digestforum, $user);

        $this->expectException(processing_failed_exception::class);
        $this->expectExceptionMessage('Unable to post your reply, since the total attachment size (3 bytes) is greater than ' .
            'the maximum size allowed for the digest forum (2 bytes)');
        $this->reply_handler_process_message($post, 'My reply', 'Hello', (object) [
            'filename' => 'Foo.txt',
            'content' => 'Foo',
            'filesize' => 3,
        ]);
    }

    /**
     * Helper to generate discussion/post data
     *
     * @param stdClass $digestforum
     * @param stdClass $user
     * @return stdClass[]
     */
    private function create_discussion_with_post(stdClass $digestforum, stdClass $user): array {
        /** @var mod_digestforum_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_digestforum');

        $discussion = $generator->create_discussion(['course' => $digestforum->course, 'digestforum' => $digestforum->id, 'userid' => $user->id]);
        $post = $generator->create_post(['discussion' => $discussion->id, 'userid' => $user->id]);

        return [$discussion, $post];
    }

    /**
     * Helper to process message details using the reply handler
     *
     * @param stdClass $post
     * @param string $subject
     * @param string $message
     * @param stdClass $attachment
     * @return string Output buffer from the handler
     */
    private function reply_handler_process_message(
        stdClass $post,
        string $subject,
        string $message,
        stdClass $attachment,
    ): string {

        // Start capturing output.
        ob_start();

        /** @var reply_handler $handler */
        $handler = manager::get_handler(reply_handler::class);

        try {
            $handler->process_message(
                (object) [
                    'datavalue' => $post->id,
                ],
                (object) [
                    'envelope' => (object) [
                        'subject' => $subject,
                    ],
                    'plain' => $message,
                    'attachments' => [
                        'attachment' => [
                            $attachment,
                        ],
                    ],
                    'timestamp' => time(),
                ],
            );
        } catch (Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }

        // Return captured output buffer.
        return (string) ob_get_clean();
    }
}
