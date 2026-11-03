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

namespace mod_digestforum\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/digestforum/lib.php');

use core_external\external_api;
use mod_digestforum\external\set_digestforum_subscription;
use mod_digestforum\subscriptions;

/**
 * Tests for the set_digestforum_subscription external function.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2025 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_digestforum\external\set_digestforum_subscription::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('execute')]
final class set_digestforum_subscription_test extends \core_external\tests\externallib_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        // We must clear the subscription caches.
        // This has to be done both before each test, and after in case of other tests using these functions.
        subscriptions::reset_digestforum_cache();
    }

    #[\Override]
    public function tearDown(): void {
        // We must clear the subscription caches.
        // This has to be done both before each test, and after in case of other tests using these functions.
        subscriptions::reset_digestforum_cache();
        parent::tearDown();
    }

    /**
     * Test execute method.
     *
     *
     * @param bool|null $initialstate Initialise subscription state, null means no initial state.
     * @param bool $targetstate Expected target state of the subscription.
     * @param int $subscriptionmode Subscription mode for the digestforum.
     * @param bool $expectedexception Whether an exception is expected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('execute_provider')]
    public function test_execute(
        ?bool $initialstate,
        bool $targetstate,
        int $subscriptionmode = DFORUM_CHOOSESUBSCRIBE,
        bool $expectedexception = false,
    ): void {

        $this->resetAfterTest();

        $user = self::getDataGenerator()->create_user();
        $course = self::getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $digestforum = self::getDataGenerator()->create_module(
            'digestforum',
            [
                'course' => $course->id,
                'forcesubscribe' => $subscriptionmode,
            ]
        );

        if ($expectedexception) {
            $this->expectException(\moodle_exception::class);
        } else if ($initialstate !== null) {
            // Set the initial state of the subscription.
            if ($initialstate) {
                subscriptions::subscribe_user($user->id, $digestforum);
            } else {
                subscriptions::unsubscribe_user($user->id, $digestforum);
            }
            $this->assertEquals($initialstate, subscriptions::is_subscribed($user->id, $digestforum));
        }

        $return = external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute($digestforum->id, $targetstate),
        );
        $this->assertEquals($targetstate, $return['userstate']['subscribed']);
        $this->assertEquals($targetstate, subscriptions::is_subscribed($user->id, $digestforum));
    }

    /**
     * Data provider for test_execute.
     *
     * @return array The data provider array.
     */
    public static function execute_provider(): array {
        return [
            'Subscription initially false, set to true' => [
                'initialstate' => false,
                'targetstate' => true,
            ],
            'Subscription initially true, set to false' => [
                'initialstate' => true,
                'targetstate' => false,
            ],
            'Subscription initially false, set to false' => [
                'initialstate' => false,
                'targetstate' => false,
            ],
            'Subscription initially true, set to true' => [
                'initialstate' => true,
                'targetstate' => true,
            ],
            'Subscription forced on' => [
                'initialstate' => null,
                'targetstate' => true,
                'subscriptionmode' => DFORUM_FORCESUBSCRIBE,
                'expectedexception' => true,
            ],
            'Subscription forced off' => [
                'initialstate' => null,
                'targetstate' => false,
                'subscriptionmode' => DFORUM_DISALLOWSUBSCRIBE,
                'expectedexception' => true,
            ],
            'Subscription initial on' => [
                'initialstate' => true,
                'targetstate' => false,
                'subscriptionmode' => DFORUM_INITIALSUBSCRIBE,
            ],
        ];
    }

    /**
     * Test execute method when digestforum is not subscribable.
     */
    public function test_execute_not_subscribable(): void {

        $this->resetAfterTest();

        $admin = get_admin();
        $teacher = self::getDataGenerator()->create_user();
        $student = self::getDataGenerator()->create_user();
        $course = self::getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $digestforum = self::getDataGenerator()->create_module('digestforum', [
            'course' => $course->id,
            'forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE,
        ]);

        // Admin user can subscribe to a digestforum that does not allow subscriptions.
        $this->setAdminUser();
        $return = external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute($digestforum->id, true),
        );
        $this->assertEquals(true, $return['userstate']['subscribed']);
        $this->assertEquals(true, subscriptions::is_subscribed($admin->id, $digestforum));

        // Teacher user can subscribe to a digestforum that does not allow subscriptions because they have the capability.
        $this->setUser($teacher);
        $return = external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute($digestforum->id, true),
        );
        $this->assertEquals(true, $return['userstate']['subscribed']);
        $this->assertEquals(true, subscriptions::is_subscribed($teacher->id, $digestforum));

        // Attempt to subscribe to a digestforum that does not allow subscriptions without the required capability.
        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute($digestforum->id, true),
        );
    }

    /**
     * Test execute method when digestforum does not exist.
     */
    public function test_execute_unexisting_digestforum(): void {

        $this->resetAfterTest();

        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute(9999, true),
        );
    }

    /**
     * Test execute method when user is not enrolled in the course of the digestforum.
     */
    public function test_execute_unenrolled_user(): void {

        $this->resetAfterTest();

        $user = self::getDataGenerator()->create_user();
        $course = self::getDataGenerator()->create_course();
        $this->setUser($user);
        $digestforum = self::getDataGenerator()->create_module('digestforum', ['course' => $course->id]);

        $this->expectException(\moodle_exception::class);
        external_api::clean_returnvalue(
            set_digestforum_subscription::execute_returns(),
            set_digestforum_subscription::execute($digestforum->id, true),
        );
    }
}
