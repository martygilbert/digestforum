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
 * Forum data mapper.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\data_mappers\legacy;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\local\entities\digestforum as digestforum_entity;
use stdClass;

/**
 * Convert a digestforum entity into an stdClass.
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum {
    /**
     * Convert a list of digestforum entities into stdClasses.
     *
     * @param digestforum_entity[] $digestforums The digestforums to convert.
     * @return stdClass[]
     */
    public function to_legacy_objects(array $digestforums): array {
        return array_map(function (digestforum_entity $digestforum) {
            return (object) [
                'id' => $digestforum->get_id(),
                'course' => $digestforum->get_course_id(),
                'type' => $digestforum->get_type(),
                'name' => $digestforum->get_name(),
                'intro' => $digestforum->get_intro(),
                'introformat' => $digestforum->get_intro_format(),
                'assessed' => $digestforum->get_rating_aggregate(),
                'assesstimestart' => $digestforum->get_assess_time_start(),
                'assesstimefinish' => $digestforum->get_assess_time_finish(),
                'scale' => $digestforum->get_scale(),
                'grade_digestforum' => $digestforum->get_grade_for_digestforum(),
                'grade_digestforum_notify' => $digestforum->should_notify_students_default_when_grade_for_digestforum(),
                'maxbytes' => $digestforum->get_max_bytes(),
                'maxattachments' => $digestforum->get_max_attachments(),
                'forcesubscribe' => $digestforum->get_subscription_mode(),
                'trackingtype' => $digestforum->get_tracking_type(),
                'rsstype' => $digestforum->get_rss_type(),
                'rssarticles' => $digestforum->get_rss_articles(),
                'timemodified' => $digestforum->get_time_modified(),
                'warnafter' => $digestforum->get_warn_after(),
                'blockafter' => $digestforum->get_block_after(),
                'blockperiod' => $digestforum->get_block_period(),
                'completiondiscussions' => $digestforum->get_completion_discussions(),
                'completionreplies' => $digestforum->get_completion_replies(),
                'completionposts' => $digestforum->get_completion_posts(),
                'displaywordcount' => $digestforum->should_display_word_count(),
                'lockdiscussionafter' => $digestforum->get_lock_discussions_after(),
                'duedate' => $digestforum->get_due_date(),
                'cutoffdate' => $digestforum->get_cutoff_date(),
                'showimmediately' => $digestforum->get_showimmediately(),
            ];
        }, $digestforums);
    }

    /**
     * Convert a digestforum entity into an stdClass.
     *
     * @param digestforum_entity $digestforum The digestforum to convert.
     * @return stdClass
     */
    public function to_legacy_object(digestforum_entity $digestforum): stdClass {
        return $this->to_legacy_objects([$digestforum])[0];
    }
}
