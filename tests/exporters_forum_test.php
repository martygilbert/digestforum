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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/digestforum/lib.php');

use mod_digestforum\local\exporters\digestforum as digestforum_exporter;

/**
 * The discussion digestforum tests.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exporters_digestforum_test extends \advanced_testcase {

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
        // // This has to be done both before each test, and after in case of other tests using these functions.
        subscriptions::reset_digestforum_cache();
        parent::tearDown();
    }

    /**
     * Test the export function returns expected values.
     */
    public function test_export(): void {
        global $PAGE;
        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('core');
        $datagenerator = $this->getDataGenerator();
        $user = $datagenerator->create_user();
        $course = $datagenerator->create_course();
        $digestforum = $datagenerator->create_module('digestforum', [
            'course' => $course->id,
            'groupmode' => VISIBLEGROUPS,
            'forcesubscribe' => DFORUM_FORCESUBSCRIBE,
        ]);
        $coursemodule = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = \context_module::instance($coursemodule->id);
        $entityfactory = \mod_digestforum\local\container::get_entity_factory();
        $digestforum = $entityfactory->get_digestforum_from_stdClass($digestforum, $context, $coursemodule, $course);

        $exporter = new digestforum_exporter($digestforum, [
            'legacydatamapperfactory' => \mod_digestforum\local\container::get_legacy_data_mapper_factory(),
            'urlfactory' => \mod_digestforum\local\container::get_url_factory(),
            'capabilitymanager' => (\mod_digestforum\local\container::get_manager_factory())->get_capability_manager($digestforum),
            'user' => $user,
            'currentgroup' => null,
            'vaultfactory' => \mod_digestforum\local\container::get_vault_factory()
        ]);

        $exporteddigestforum = $exporter->export($renderer);

        $this->assertEquals($digestforum->get_id(), $exporteddigestforum->id);
        $this->assertEquals(VISIBLEGROUPS, $exporteddigestforum->state['groupmode']);
        $this->assertEquals(false, $exporteddigestforum->userstate['tracked']);
        $this->assertEquals(false, $exporteddigestforum->userstate['subscribed']);
        $this->assertEquals(false, $exporteddigestforum->capabilities['viewdiscussions']);
        $this->assertEquals(false, $exporteddigestforum->capabilities['create']);
        $this->assertEquals(false, $exporteddigestforum->capabilities['subscribe']);
        $this->assertNotEquals(null, $exporteddigestforum->urls['create']);
        $this->assertNotEquals(null, $exporteddigestforum->urls['markasread']);

        // Enrol the user in the course and check the capabilities and user state.
        $datagenerator->enrol_user($user->id, $course->id);
        $exporteddigestforum = $exporter->export($renderer);
        $this->assertEquals(true, $exporteddigestforum->userstate['subscribed']);
        $this->assertEquals(true, $exporteddigestforum->capabilities['viewdiscussions']);
    }
}
