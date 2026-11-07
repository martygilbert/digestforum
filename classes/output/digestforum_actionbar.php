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

use renderable;
use renderer_base;
use templatable;
use moodle_url;
use help_icon;
use mod_digestforum\local\entities\digestforum as digestforum_entity;

/**
 * Render activity page for tertiary nav
 *
 * Render elements search digestforum, add new discussion button and subscribe all
 * to the page action.
 *
 * @package mod_digestforum
 * @copyright 2021 Sujith Haridasan <sujith@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum_actionbar implements renderable, templatable {
    /**
     * @var digestforum_entity $digestforum
     */
    private $digestforum;

    /**
     * @var \stdClass $course
     */
    private $course;

    /**
     * @var mixed $groupid
     */
    private $groupid;

    /**
     * @var string $search
     */
    private $search;

    /**
     * digestforum_actionbar constructor.
     *
     * @param digestforum_entity $digestforum The digestforum object.
     * @param \stdClass $course The course object.
     * @param int|null $groupid The group id.
     * @param string $search The search string.
     */
    public function __construct(digestforum_entity $digestforum, \stdClass $course, ?int $groupid, string $search) {
        $this->digestforum = $digestforum;
        $this->course = $course;
        $this->groupid = $groupid;
        $this->search = $search;
    }

    /**
     * Render the new discussion button.
     *
     * @return string HTML button
     */
    private function get_new_discussion_topic_button(): string {
        global $USER;
        $renderfactory = \mod_digestforum\local\container::get_renderer_factory();
        $discussionrenderer = $renderfactory->get_discussion_list_renderer($this->digestforum);
        return $discussionrenderer->render_new_discussion($USER, $this->groupid);
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output The render_base object.
     * @return array data for the template
     */
    public function export_for_template(renderer_base $output): array {
        global $USER;
        $actionurl = (new moodle_url('/mod/digestforum/search.php'))->out(false);
        $helpicon = new help_icon('search', 'core');
        $hiddenfields = [
            (object) ['name' => 'id', 'value' => $this->course->id],
        ];
        $shownewdiscussionbtn = '';
        if ($this->digestforum->get_type() !== 'single') {
            $shownewdiscussionbtn = $this->get_new_discussion_topic_button();
        }
        $data = [
            'action' => $actionurl,
            'hiddenfields' => $hiddenfields,
            'query' => $this->search,
            'helpicon' => $helpicon->export_for_template($output),
            'inputname' => 'search',
            'searchstring' => get_string('searchdigestforums', 'mod_digestforum'),
            'newdiscussionbtn' => $shownewdiscussionbtn,
        ];

        $legacydatamapperfactory = \mod_digestforum\local\container::get_legacy_data_mapper_factory();
        $digestforumobject = $legacydatamapperfactory->get_digestforum_data_mapper()->to_legacy_object($this->digestforum);
        $context = $this->digestforum->get_context();
        $activeenrolled = is_enrolled($context, $USER, '', true);
        $canmanage = has_capability('mod/digestforum:managesubscriptions', $context);
        $cansubscribe = $activeenrolled && !($this->digestforum->get_subscription_mode() === DFORUM_FORCESUBSCRIBE) &&
            (!($this->digestforum->get_subscription_mode() === DFORUM_DISALLOWSUBSCRIBE) || $canmanage);
        if ($cansubscribe) {
            $cm = $this->digestforum->get_course_module_record();
            $returnurl = (new moodle_url('/mod/digestforum/view.php', ['id' => $cm->id]))->out(false);
            if (!\mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforumobject, null, $cm)) {
                $data['subscribetodigestforum'] = (new moodle_url(
                    '/mod/digestforum/subscribe.php',
                    ['id' => $digestforumobject->id, 'sesskey' => sesskey(), 'returnurl' => $returnurl]
                ))->out(false);
            } else {
                $data['unsubscribefromdigestforum'] = (new moodle_url(
                    '/mod/digestforum/subscribe.php',
                    ['id' => $digestforumobject->id, 'sesskey' => sesskey(), 'returnurl' => $returnurl]
                ))->out(false);
            }
        }
        return $data;
    }
}
