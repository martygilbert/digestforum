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

use mod_digestforum\local\entities\discussion as discussion_entity;
use mod_digestforum\local\entities\digestforum as digestforum_entity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/rating/lib.php');

/**
 * The digestforum entity tests.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_digestforum\local\entities\digestforum::class)]
final class entities_digestforum_test extends \advanced_testcase {
    /**
     * Test the entity returns expected values.
     */
    public function test_entity(): void {
        $this->resetAfterTest();

        $time = time() - 10;
        $discussion = new discussion_entity(
            1,
            2,
            3,
            'test discussion',
            4,
            5,
            6,
            false,
            $time,
            $time,
            0,
            0,
            false,
            0
        );

        $past = time() - 100;
        $course = $this->getDataGenerator()->create_course();
        $digestforum = $this->getDataGenerator()->create_module('digestforum', ['course' => $course->id]);
        $coursemodule = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = \context_module::instance($coursemodule->id);
        $effectivegroupmode = NOGROUPS;
        $id = 1;
        $courseid = 2;
        $type = 'standard';
        $name = 'test digestforum';
        $intro = 'this is the intro';
        $introformat = FORMAT_MOODLE;
        $assessed = RATING_AGGREGATE_NONE;
        $assesstimestart = 0;
        $assesstimefinish = 0;
        $scale = 0;
        $gradedigestforum = 0;
        $maxbytes = 200;
        $maxattachments = 5;
        $forcesubscribe = 0;
        $trackingtype = 1;
        $rsstype = 0;
        $rssarticles = 0;
        $timemodified = $past;
        $warnafter = 0;
        $blockafter = 0;
        $blockperiod = 0;
        $completiondiscussions = 0;
        $completionreplies = 0;
        $completionposts = 0;
        $displaywordcount = false;
        $lockdiscussionafter = 0;
        $duedate = 0;
        $cutoffdate = 0;
        $sendnotification = false;
        $showimmediately = false;
        $digestforum = new digestforum_entity(
            $context,
            $coursemodule,
            $course,
            $effectivegroupmode,
            $id,
            $courseid,
            $type,
            $name,
            $intro,
            $introformat,
            $assessed,
            $assesstimestart,
            $assesstimefinish,
            $scale,
            $gradedigestforum,
            $sendnotification,
            $maxbytes,
            $maxattachments,
            $forcesubscribe,
            $trackingtype,
            $rsstype,
            $rssarticles,
            $timemodified,
            $warnafter,
            $blockafter,
            $blockperiod,
            $completiondiscussions,
            $completionreplies,
            $completionposts,
            $displaywordcount,
            $lockdiscussionafter,
            $duedate,
            $cutoffdate,
            $showimmediately
        );

        $this->assertEquals($context, $digestforum->get_context());
        $this->assertEquals($coursemodule, $digestforum->get_course_module_record());
        $this->assertEquals($coursemodule, $digestforum->get_course_module_record());
        $this->assertEquals($effectivegroupmode, $digestforum->get_effective_group_mode());
        $this->assertEquals(false, $digestforum->is_in_group_mode());
        $this->assertEquals($course, $digestforum->get_course_record());
        $this->assertEquals($id, $digestforum->get_id());
        $this->assertEquals($courseid, $digestforum->get_course_id());
        $this->assertEquals($name, $digestforum->get_name());
        $this->assertEquals($intro, $digestforum->get_intro());
        $this->assertEquals($introformat, $digestforum->get_intro_format());
        $this->assertEquals($assessed, $digestforum->get_rating_aggregate());
        // Rating aggregate is set to none.
        $this->assertEquals(false, $digestforum->has_rating_aggregate());
        $this->assertEquals($assesstimestart, $digestforum->get_assess_time_start());
        $this->assertEquals($assesstimefinish, $digestforum->get_assess_time_finish());
        $this->assertEquals($scale, $digestforum->get_scale());
        $this->assertEquals($gradedigestforum, $digestforum->get_grade_for_digestforum());
        $this->assertEquals($maxbytes, $digestforum->get_max_bytes());
        $this->assertEquals($maxattachments, $digestforum->get_max_attachments());
        $this->assertEquals($forcesubscribe, $digestforum->get_subscription_mode());
        $this->assertEquals($trackingtype, $digestforum->get_tracking_type());
        $this->assertEquals($rsstype, $digestforum->get_rss_type());
        $this->assertEquals($rssarticles, $digestforum->get_rss_articles());
        $this->assertEquals($timemodified, $digestforum->get_time_modified());
        $this->assertEquals($warnafter, $digestforum->get_warn_after());
        $this->assertEquals($blockafter, $digestforum->get_block_after());
        $this->assertEquals($blockperiod, $digestforum->get_block_period());
        $this->assertEquals(false, $digestforum->has_blocking_enabled());
        $this->assertEquals($completiondiscussions, $digestforum->get_completion_discussions());
        $this->assertEquals($completionreplies, $digestforum->get_completion_replies());
        $this->assertEquals($completionposts, $digestforum->get_completion_posts());
        $this->assertEquals($displaywordcount, $digestforum->should_display_word_count());
        $this->assertEquals($lockdiscussionafter, $digestforum->get_lock_discussions_after());
        $this->assertEquals(false, $digestforum->has_lock_discussions_after());
        $this->assertEquals(false, $digestforum->is_discussion_locked($discussion));
        $this->assertEquals(false, $digestforum->has_due_date());
        $this->assertEquals(false, $digestforum->is_due_date_reached());
        $this->assertEquals(false, $digestforum->has_cutoff_date());
        $this->assertEquals(false, $digestforum->is_cutoff_date_reached());
    }
}
