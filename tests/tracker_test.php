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

/**
 * Tests for digest open tracking: recording views, open rates, and message ids.
 *
 * @package    mod_digestforum
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tracker_test extends \advanced_testcase {

    /**
     * Add a tracker record.
     *
     * @param int $userid
     * @param int $digestforumid
     * @param string $digestdate yyyy-mm-dd
     * @param int $numviews
     * @return int the tracker id
     */
    protected function add_tracker(int $userid, int $digestforumid, string $digestdate, int $numviews = 0): int {
        global $DB;

        return $DB->insert_record('digestforum_tracker', (object) [
            'mdluserid' => $userid,
            'digestforumid' => $digestforumid,
            'digestdate' => $digestdate,
            'numviews' => $numviews,
        ]);
    }

    /**
     * With no tracking data the open rate is -1.
     */
    public function test_open_rate_no_data(): void {
        $this->resetAfterTest();

        $this->assertSame(-1, mod_digestforum_get_month_open_rate(2, 1, '2026-11-04'));
        $this->assertSame(-1, mod_digestforum_get_month_open_rate(2, 1));
    }

    /**
     * The open rate is the rounded percentage of digests opened, for that user and digestforum only.
     */
    public function test_open_rate(): void {
        $this->resetAfterTest();

        $this->add_tracker(2, 1, '2026-11-01', 1);
        $this->add_tracker(2, 1, '2026-11-02', 3);
        $this->add_tracker(2, 1, '2026-11-03', 0);

        // Other users and digestforums are not counted.
        $this->add_tracker(3, 1, '2026-11-01', 0);
        $this->add_tracker(2, 5, '2026-11-01', 0);

        $this->assertSame(67, mod_digestforum_get_month_open_rate(2, 1, '2026-11-04'));
        $this->assertSame(0, mod_digestforum_get_month_open_rate(3, 1, '2026-11-04'));
        $this->assertSame(0, mod_digestforum_get_month_open_rate(2, 5, '2026-11-04'));
    }

    /**
     * The open rate only counts the month of the digest date, unless no date is given.
     */
    public function test_open_rate_month(): void {
        $this->resetAfterTest();

        $this->add_tracker(2, 1, '2026-10-30', 0);
        $this->add_tracker(2, 1, '2026-10-31', 0);
        $this->add_tracker(2, 1, '2026-11-01', 1);
        $this->add_tracker(2, 1, '2026-11-2', 0);

        // November only, including the single-digit day format.
        $this->assertSame(50, mod_digestforum_get_month_open_rate(2, 1, '2026-11-04'));
        $this->assertSame(50, mod_digestforum_get_month_open_rate(2, 1, '2026-11-4'));
        $this->assertSame(0, mod_digestforum_get_month_open_rate(2, 1, '2026-10-15'));
        $this->assertSame(-1, mod_digestforum_get_month_open_rate(2, 1, '2026-12-01'));

        // All time.
        $this->assertSame(25, mod_digestforum_get_month_open_rate(2, 1));

        // An invalid date falls back to all time.
        $this->assertSame(25, mod_digestforum_get_month_open_rate(2, 1, 'November'));
    }

    /**
     * Viewing an unknown tracker does nothing.
     */
    public function test_record_view_unknown(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertFalse(mod_digestforum_record_digest_view(12345));
        $this->assertEquals(0, $DB->count_records('digestforum_tracker'));
    }

    /**
     * Views within a week of the digest count as opens, and the first and last viewed times are kept.
     */
    public function test_record_view(): void {
        global $DB;
        $this->resetAfterTest();

        $digesttime = (new \DateTime('2026-11-04 07:00:00'))->getTimestamp();
        $id = $this->add_tracker(2, 1, '2026-11-04');

        $this->assertTrue(mod_digestforum_record_digest_view($id, $digesttime + HOURSECS));
        $tracker = $DB->get_record('digestforum_tracker', ['id' => $id]);
        $this->assertEquals(1, $tracker->numviews);
        $this->assertEquals($digesttime + HOURSECS, $tracker->firstviewed);
        $this->assertEquals($digesttime + HOURSECS, $tracker->lastviewed);

        $this->assertTrue(mod_digestforum_record_digest_view($id, $digesttime + 6 * DAYSECS));
        $tracker = $DB->get_record('digestforum_tracker', ['id' => $id]);
        $this->assertEquals(2, $tracker->numviews);
        $this->assertEquals($digesttime + HOURSECS, $tracker->firstviewed);
        $this->assertEquals($digesttime + 6 * DAYSECS, $tracker->lastviewed);
    }

    /**
     * Views more than a week after the digest update the view times, but do not count as opens.
     */
    public function test_record_view_after_a_week(): void {
        global $DB;
        $this->resetAfterTest();

        $digesttime = (new \DateTime('2026-11-04 07:00:00'))->getTimestamp();
        $id = $this->add_tracker(2, 1, '2026-11-4');

        $this->assertTrue(mod_digestforum_record_digest_view($id, $digesttime + 8 * DAYSECS));
        $tracker = $DB->get_record('digestforum_tracker', ['id' => $id]);
        $this->assertEquals(0, $tracker->numviews);
        $this->assertEquals($digesttime + 8 * DAYSECS, $tracker->firstviewed);
        $this->assertEquals($digesttime + 8 * DAYSECS, $tracker->lastviewed);
    }

    /**
     * Each digestforum, recipient and day gets its own message id.
     */
    public function test_email_message_id(): void {
        $this->resetAfterTest();

        $id = digestforum_get_email_message_id(1, 2, '2026-11-04');
        $this->assertEquals($id, digestforum_get_email_message_id(1, 2, '2026-11-04'));
        $this->assertNotEquals($id, digestforum_get_email_message_id(1, 2, '2026-11-05'));
        $this->assertNotEquals($id, digestforum_get_email_message_id(1, 3, '2026-11-04'));
        $this->assertNotEquals($id, digestforum_get_email_message_id(4, 2, '2026-11-04'));

        // The date defaults to today.
        $this->assertEquals(digestforum_get_email_message_id(1, 2, userdate(time(), '%Y%m%d')),
                digestforum_get_email_message_id(1, 2));
    }
}
