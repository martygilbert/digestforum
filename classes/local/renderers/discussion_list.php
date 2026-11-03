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
 * Discussion list renderer.
 *
 * @package    mod_digestforum
 * @copyright  2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\renderers;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\grades\digestforum_gradeitem;
use mod_digestforum\local\entities\digestforum as digestforum_entity;
use mod_digestforum\local\factories\legacy_data_mapper as legacy_data_mapper_factory;
use mod_digestforum\local\factories\exporter as exporter_factory;
use mod_digestforum\local\factories\vault as vault_factory;
use mod_digestforum\local\factories\url as url_factory;
use mod_digestforum\local\managers\capability as capability_manager;
use mod_digestforum\local\vaults\discussion_list as discussion_list_vault;
use renderer_base;
use stdClass;
use core\output\notification;
use mod_digestforum\local\data_mappers\legacy\digestforum;
use mod_digestforum\local\factories\builder as builder_factory;

require_once($CFG->dirroot . '/mod/digestforum/lib.php');

/**
 * The discussion list renderer.
 *
 * @package    mod_digestforum
 * @copyright  2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discussion_list {
    /** @var digestforum_entity The digestforum being rendered */
    private $digestforum;

    /** @var stdClass The DB record for the digestforum being rendered */
    private $digestforumrecord;

    /** @var renderer_base The renderer used to render the view */
    private $renderer;

    /** @var legacy_data_mapper_factory $legacydatamapperfactory Legacy data mapper factory */
    private $legacydatamapperfactory;

    /** @var exporter_factory $exporterfactory Exporter factory */
    private $exporterfactory;

    /** @var vault_factory $vaultfactory Vault factory */
    private $vaultfactory;

    /** @var capability_manager $capabilitymanager Capability manager */
    private $capabilitymanager;

    /** @var url_factory $urlfactory URL factory */
    private $urlfactory;

    /** @var array $notifications List of notification HTML */
    private $notifications;

    /** @var builder_factory $builderfactory Builder factory */
    private $builderfactory;

    /** @var callable $postprocessfortemplate Function to process exported posts before template rendering */
    private $postprocessfortemplate;

    /** @var string $template The template to use when displaying */
    private $template;

    /** @var gradeitem The gradeitem instance associated with this digestforum */
    private $digestforumgradeitem;

    /**
     * Constructor for a new discussion list renderer.
     *
     * @param   digestforum_entity        $digestforum The digestforum entity to be rendered
     * @param   renderer_base       $renderer The renderer used to render the view
     * @param   legacy_data_mapper_factory $legacydatamapperfactory The factory used to fetch a legacy record
     * @param   exporter_factory    $exporterfactory The factory used to fetch exporter instances
     * @param   vault_factory       $vaultfactory The factory used to fetch the vault instances
     * @param   builder_factory     $builderfactory The factory used to fetch the builder instances
     * @param   capability_manager  $capabilitymanager The managed used to check capabilities on the digestforum
     * @param   url_factory         $urlfactory The factory used to create URLs in the digestforum
     * @param   string              $template
     * @param   notification[]      $notifications A list of any notifications to be displayed within the page
     * @param   callable|null       $postprocessfortemplate Callback function to process discussion lists for templates
     */
    public function __construct(
        digestforum_entity $digestforum,
        renderer_base $renderer,
        legacy_data_mapper_factory $legacydatamapperfactory,
        exporter_factory $exporterfactory,
        vault_factory $vaultfactory,
        builder_factory $builderfactory,
        capability_manager $capabilitymanager,
        url_factory $urlfactory,
        digestforum_gradeitem $digestforumgradeitem,
        string $template,
        array $notifications = [],
        ?callable $postprocessfortemplate = null
    ) {
        $this->digestforum = $digestforum;
        $this->renderer = $renderer;
        $this->legacydatamapperfactory = $legacydatamapperfactory;
        $this->exporterfactory = $exporterfactory;
        $this->vaultfactory = $vaultfactory;
        $this->builderfactory = $builderfactory;
        $this->capabilitymanager = $capabilitymanager;

        $this->urlfactory = $urlfactory;
        $this->notifications = $notifications;
        $this->postprocessfortemplate = $postprocessfortemplate;
        $this->template = $template;
        $this->digestforumgradeitem = $digestforumgradeitem;

        $digestforumdatamapper = $this->legacydatamapperfactory->get_digestforum_data_mapper();
        $this->digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
    }

    /**
     * Render for the specified user.
     *
     * @param   stdClass    $user The user to render for
     * @param   cm_info     $cm The course module info for this discussion list
     * @param   int         $groupid The group to render
     * @param   int         $sortorder The sort order to use when selecting the discussions in the list
     * @param   int         $pageno The zero-indexed page number to use
     * @param   int         $pagesize The number of discussions to show on the page
     * @param   int         $displaymode The discussion display mode
     * @param   bool        $enablediscussioncreation To show the discussion button.
     * @return  string      The rendered content for display
     */
    public function render(
        stdClass $user,
        \cm_info $cm,
        ?int $groupid,
        ?int $sortorder,
        ?int $pageno,
        ?int $pagesize,
        ?int $displaymode = null,
        bool $enablediscussioncreation = true
    ): string {
        global $PAGE;

        $digestforum = $this->digestforum;
        $course = $digestforum->get_course_record();

        $digestforumexporter = $this->exporterfactory->get_digestforum_exporter(
            $user,
            $this->digestforum,
            $groupid
        );

        $pagesize = $this->get_page_size($pagesize);
        $pageno = $this->get_page_number($pageno);

        // Count all digestforum discussion posts.
        $alldiscussionscount = mod_digestforum_count_all_discussions($digestforum, $user, $groupid);

        // Get all digestforum discussion summaries.
        $discussions = mod_digestforum_get_discussion_summaries($digestforum, $user, $groupid, $sortorder, $pageno, $pagesize);

        $capabilitymanager = $this->capabilitymanager;
        $hasanyactions = false;
        $hasanyactions = $hasanyactions || $capabilitymanager->can_favourite_discussion($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_pin_discussions($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_manage_digestforum($user);

        $digestforumview = [
            'digestforum' => (array) $digestforumexporter->export($this->renderer),
            'contextid' => $digestforum->get_context()->id,
            'cmid' => $cm->id,
            'groupid' => $groupid,
            'name' => format_string($digestforum->get_name()),
            'courseid' => $course->id,
            'coursename' => format_string($course->shortname),
            'experimentaldisplaymode' => $displaymode == DFORUM_MODE_NESTED_V2,
            'gradingcomponent' => $this->digestforumgradeitem->get_grading_component_name(),
            'gradingcomponentsubtype' => $this->digestforumgradeitem->get_grading_component_subtype(),
            'sendstudentnotifications' => $digestforum->should_notify_students_default_when_grade_for_digestforum(),
            'gradeonlyactiveusers' => $this->digestforumgradeitem->should_grade_only_active_users(),
            'hasanyactions' => $hasanyactions,
            'groupchangemenu' => groups_print_activity_menu(
                $cm,
                $this->urlfactory->get_digestforum_view_url_from_digestforum($digestforum),
                true
            ),
            'hasmore' => ($alldiscussionscount > $pagesize),
            'notifications' => $this->get_notifications($user, $groupid),
            'settings' => [
                'excludetext' => true,
                'togglemoreicon' => true,
                'excludesubscription' => true
            ],
            'totaldiscussioncount' => $alldiscussionscount,
            'userid' => $user->id,
            'visiblediscussioncount' => count($discussions),
            'enablediscussioncreation' => $enablediscussioncreation,
        ];

        if ($digestforumview['digestforum']['capabilities']['create']) {
            $digestforumview['newdiscussionhtml'] = $this->get_discussion_form($user, $cm, $groupid);
        }

        if (!$discussions) {
            return $this->renderer->render_from_template($this->template, $digestforumview);
        }

        if ($this->postprocessfortemplate !== null) {
            // We've got some post processing to do!
            $exportedposts = ($this->postprocessfortemplate) ($discussions, $user, $digestforum);
        }

        $baseurl = new \moodle_url($PAGE->url, ['o' => $sortorder, 's' => $pagesize]);

        $digestforumview = array_merge(
            $digestforumview,
            [
                'pagination' => $this->renderer->render(new \paging_bar($alldiscussionscount, $pageno, $pagesize, $baseurl, 'p')),
            ],
            $exportedposts
        );

        $firstdiscussion = reset($discussions);
        $digestforumview['firstgradeduserid'] = $firstdiscussion->get_latest_post_author()->get_id();

        return $this->renderer->render_from_template($this->template, $digestforumview);
    }

    /**
     * Add new discussion button to the action bar for tertiary nav.
     *
     * @param stdClass $user The user object.
     * @param int|null $groupid The group id.
     * @return string rendered HTML string
     */
    public function render_new_discussion(stdClass $user, ?int $groupid): string {
        $digestforumexporter = $this->exporterfactory->get_digestforum_exporter(
            $user,
            $this->digestforum,
            $groupid
        );

        $digestforumview = [
            'digestforum' => (array) $digestforumexporter->export($this->renderer),
        ];

        return $this->renderer->render_from_template('mod_digestforum/digestforum_new_discussion_actionbar', $digestforumview);
    }

    /**
     * Get the mod_digestforum_post_form. This is the default boiler plate from mod_digestforum/post_form.php with the inpage flag caveat
     *
     * @param stdClass $user The user the form is being generated for
     * @param \cm_info $cm
     * @param int $groupid The groupid if any
     *
     * @return string The rendered html
     */
    private function get_discussion_form(stdClass $user, \cm_info $cm, ?int $groupid) {
        $digestforum = $this->digestforum;
        $digestforumrecord = $this->legacydatamapperfactory->get_digestforum_data_mapper()->to_legacy_object($digestforum);
        $modcontext = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($digestforum->get_course_id());
        $post = (object) [
            'course' => $digestforum->get_course_id(),
            'digestforum' => $digestforum->get_id(),
            'discussion' => 0,           // Ie discussion # not defined yet.
            'parent' => 0,
            'subject' => '',
            'userid' => $user->id,
            'message' => '',
            'messageformat' => editors_get_preferred_format(),
            'messagetrust' => 0,
            'groupid' => $groupid,
        ];
        $thresholdwarning = digestforum_check_throttling($digestforumrecord, $cm);

        $formparams = array(
            'course' => $digestforum->get_course_record(),
            'cm' => $cm,
            'coursecontext' => $coursecontext,
            'modcontext' => $modcontext,
            'digestforum' => $digestforumrecord,
            'post' => $post,
            'subscribe' => \mod_digestforum\subscriptions::is_subscribed($user->id, $digestforumrecord,
                null, $cm),
            'thresholdwarning' => $thresholdwarning,
            'inpagereply' => true,
            'edit' => 0
        );
        $posturl = new \moodle_url('/mod/digestforum/post.php');
        $mformpost = new \mod_digestforum_post_form($posturl, $formparams, 'post', '', array('id' => 'mformdigestforum'));
        $discussionsubscribe = \mod_digestforum\subscriptions::get_user_default_subscription($digestforumrecord, $coursecontext, $cm, null);

        $params = array('reply' => 0, 'digestforum' => $digestforumrecord->id, 'edit' => 0) +
            (isset($post->groupid) ? array('groupid' => $post->groupid) : array()) +
            array(
                'userid' => $post->userid,
                'parent' => $post->parent,
                'discussion' => $post->discussion,
                'course' => $digestforum->get_course_id(),
                'discussionsubscribe' => $discussionsubscribe
            );
        $mformpost->set_data($params);

        return $mformpost->render();
    }

    /**
     * Fetch the page size to use when displaying the page.
     *
     * @param   int         $pagesize The number of discussions to show on the page
     * @return  int         The normalised page size
     */
    private function get_page_size(?int $pagesize): int {
        if (null === $pagesize || $pagesize <= 0) {
            $pagesize = discussion_list_vault::PAGESIZE_DEFAULT;
        }

        return $pagesize;
    }

    /**
     * Fetch the current page number (zero-indexed).
     *
     * @param   int         $pageno The zero-indexed page number to use
     * @return  int         The normalised page number
     */
    private function get_page_number(?int $pageno): int {
        if (null === $pageno || $pageno < 0) {
            $pageno = 0;
        }

        return $pageno;
    }

    /**
     * Get the list of notification for display.
     *
     * @param stdClass $user The viewing user
     * @param int|null $groupid The digestforum's group id
     * @return      array
     */
    private function get_notifications(stdClass $user, ?int $groupid): array {
        $notifications = $this->notifications;
        $digestforum = $this->digestforum;
        $capabilitymanager = $this->capabilitymanager;

        if ($digestforum->is_cutoff_date_reached()) {
            $notifications[] = (new notification(
                    get_string('cutoffdatereached', 'digestforum'),
                    notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($digestforum->has_blocking_enabled()) {
            $notifications[] = (new notification(
                get_string('thisdigestforumisthrottled', 'digestforum', [
                    'blockafter' => $digestforum->get_block_after(),
                    'blockperiod' => get_string('secondstotime' . $digestforum->get_block_period())
                ]),
                notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($digestforum->is_in_group_mode() && !$capabilitymanager->can_access_all_groups($user)) {
            if ($groupid === null) {
                $isvisiblegroupsmode = $digestforum->get_effective_group_mode() == VISIBLEGROUPS;
                $isgroupmember = !empty(groups_get_user_groups($digestforum->get_course_id(), $user->id)[0]);

                if (
                    !$capabilitymanager->can_post_to_my_groups($user)
                    && $isvisiblegroupsmode
                    && $isgroupmember
                ) {
                    $notifications[] = (new notification(
                        get_string('cannotadddiscussionall', 'mod_digestforum'),
                        \core\output\notification::NOTIFY_WARNING
                    ))->set_show_closebutton();
                } else {
                    $notifications[] = (new notification(
                        get_string('cannotadddiscussiongroup', 'mod_digestforum'),
                        \core\output\notification::NOTIFY_WARNING
                    ))->set_show_closebutton();
                }
            } else if (!$capabilitymanager->can_access_group($user, $groupid)) {
                $notifications[] = (new notification(
                    get_string('cannotadddiscussion', 'mod_digestforum'),
                    \core\output\notification::NOTIFY_WARNING
                ))->set_show_closebutton();
            }
        }

        if ('qanda' === $digestforum->get_type() && !$capabilitymanager->can_manage_digestforum($user)) {
            $notifications[] = (new notification(
                get_string('qandanotify', 'digestforum'),
                notification::NOTIFY_INFO
            ))->set_show_closebutton()->set_extra_classes(['mt-3']);
        }

        if ('eachuser' === $digestforum->get_type()) {
            $notifications[] = (new notification(
                get_string('allowsdiscussions', 'digestforum'),
                notification::NOTIFY_INFO)
            )->set_show_closebutton();
        }

        return array_map(function($notification) {
            return $notification->export_for_template($this->renderer);
        }, $notifications);
    }
}
