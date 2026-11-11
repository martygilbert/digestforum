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
 * Discussion renderer.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\renderers;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\local\entities\discussion as discussion_entity;
use mod_digestforum\local\entities\digestforum as digestforum_entity;
use mod_digestforum\local\entities\post as post_entity;
use mod_digestforum\local\entities\sorter as sorter_entity;
use mod_digestforum\local\factories\entity as entity_factory;
use mod_digestforum\local\factories\legacy_data_mapper as legacy_data_mapper_factory;
use mod_digestforum\local\factories\exporter as exporter_factory;
use mod_digestforum\local\factories\url as url_factory;
use mod_digestforum\local\factories\vault as vault_factory;
use mod_digestforum\local\managers\capability as capability_manager;
use mod_digestforum\local\renderers\posts as posts_renderer;
use digestforum_portfolio_caller;
use core\output\notification;
use context;
use context_module;
use html_writer;
use moodle_exception;
use moodle_page;
use moodle_url;
use rating_manager;
use renderer_base;
use single_button;
use single_select;
use stdClass;
use url_select;

require_once($CFG->dirroot . '/mod/digestforum/lib.php');
require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

/**
 * Discussion renderer class.
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discussion {
    /** @var digestforum_entity $digestforum The digestforum that the discussion belongs to */
    private $digestforum;
    /** @var discussion_entity $discussion The discussion entity */
    private $discussion;
    /** @var stdClass $discussionrecord Legacy discussion record */
    private $discussionrecord;
    /** @var stdClass $digestforumrecord Legacy digestforum record */
    private $digestforumrecord;
    /** @var int $displaymode The display mode to render the discussion in */
    private $displaymode;
    /** @var renderer_base $renderer Renderer base */
    private $renderer;
    /** @var posts_renderer $postsrenderer A posts renderer */
    private $postsrenderer;
    /** @var moodle_page $page The page this discussion is being rendered for */
    private $page;
    /** @var legacy_data_mapper_factory $legacydatamapperfactory Legacy data mapper factory */
    private $legacydatamapperfactory;
    /** @var exporter_factory $exporterfactory Exporter factory */
    private $exporterfactory;
    /** @var vault_factory $vaultfactory Vault factory */
    private $vaultfactory;
    /** @var url_factory $urlfactory URL factory */
    private $urlfactory;
    /** @var entity_factory $entityfactory Entity factory */
    private $entityfactory;
    /** @var capability_manager $capabilitymanager Capability manager */
    private $capabilitymanager;
    /** @var rating_manager $ratingmanager Rating manager */
    private $ratingmanager;
    /** @var moodle_url $baseurl The base URL for the discussion */
    private $baseurl;
    /** @var array $notifications List of HTML notifications to display */
    private $notifications;
    /** @var sorter_entity $exportedpostsorter Sorter for the exported posts */
    private $exportedpostsorter;
    /** @var callable $postprocessfortemplate Function to process exported posts before template rendering */
    private $postprocessfortemplate;

    /**
     * Constructor.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussion belongs to
     * @param discussion_entity $discussion The discussion entity
     * @param int $displaymode The display mode to render the discussion in
     * @param renderer_base $renderer Renderer base
     * @param posts_renderer $postsrenderer A posts renderer
     * @param moodle_page $page The page this discussion is being rendered for
     * @param legacy_data_mapper_factory $legacydatamapperfactory Legacy data mapper factory
     * @param exporter_factory $exporterfactory Exporter factory
     * @param vault_factory $vaultfactory Vault factory
     * @param url_factory $urlfactory URL factory
     * @param entity_factory $entityfactory Entity factory
     * @param capability_manager $capabilitymanager Capability manager
     * @param rating_manager $ratingmanager Rating manager
     * @param sorter_entity $exportedpostsorter Sorter for the exported posts
     * @param moodle_url $baseurl The base URL for the discussion
     * @param array $notifications List of HTML notifications to display
     * @param callable|null $postprocessfortemplate Post processing for template callback
     */
    public function __construct(
        digestforum_entity $digestforum,
        discussion_entity $discussion,
        int $displaymode,
        renderer_base $renderer,
        posts_renderer $postsrenderer,
        moodle_page $page,
        legacy_data_mapper_factory $legacydatamapperfactory,
        exporter_factory $exporterfactory,
        vault_factory $vaultfactory,
        url_factory $urlfactory,
        entity_factory $entityfactory,
        capability_manager $capabilitymanager,
        rating_manager $ratingmanager,
        sorter_entity $exportedpostsorter,
        moodle_url $baseurl,
        array $notifications = [],
        ?callable $postprocessfortemplate = null
    ) {
        $this->digestforum = $digestforum;
        $this->discussion = $discussion;
        $this->displaymode = $displaymode;
        $this->renderer = $renderer;
        $this->postsrenderer = $postsrenderer;
        $this->page = $page;
        $this->baseurl = $baseurl;
        $this->legacydatamapperfactory = $legacydatamapperfactory;
        $this->exporterfactory = $exporterfactory;
        $this->vaultfactory = $vaultfactory;
        $this->urlfactory = $urlfactory;
        $this->entityfactory = $entityfactory;
        $this->capabilitymanager = $capabilitymanager;
        $this->ratingmanager = $ratingmanager;
        $this->notifications = $notifications;

        $this->exportedpostsorter = $exportedpostsorter;
        $this->postprocessfortemplate = $postprocessfortemplate;

        $digestforumdatamapper = $this->legacydatamapperfactory->get_digestforum_data_mapper();
        $this->digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);

        $discussiondatamapper = $this->legacydatamapperfactory->get_discussion_data_mapper();
        $this->discussionrecord = $discussiondatamapper->to_legacy_object($discussion);
    }

    /**
     * Render the discussion for the given user in the specified display mode.
     *
     * @param stdClass $user The user viewing the discussion
     * @param post_entity $firstpost The first post in the discussion
     * @param array $replies List of replies to the first post
     * @return string HTML for the discussion
     */
    public function render(
        stdClass $user,
        post_entity $firstpost,
        array $replies
    ): string {
        global $CFG;

        $displaymode = $this->displaymode;
        $capabilitymanager = $this->capabilitymanager;
        $urlfactory = $this->urlfactory;
        $entityfactory = $this->entityfactory;

        // Make sure we can render.
        if (!$capabilitymanager->can_view_discussions($user)) {
            throw new moodle_exception('noviewdiscussionspermission', 'mod_digestforum');
        }

        $posts = array_merge([$firstpost], array_values($replies));

        if ($this->postprocessfortemplate !== null) {
            $exporteddiscussion = ($this->postprocessfortemplate)($this->discussion, $user, $this->digestforum);
        } else {
            $exporteddiscussion = $this->get_exported_discussion($user);
        }

        $hasanyactions = false;
        $hasanyactions = $hasanyactions || $capabilitymanager->can_favourite_discussion($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_pin_discussions($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_manage_digestforum($user);

        $exporteddiscussion = array_merge($exporteddiscussion, [
            'notifications' => $this->get_notifications($user),
            'html' => [
                'hasanyactions' => $hasanyactions,
                'posts' => $this->postsrenderer->render($user, [$this->digestforum], [$this->discussion], $posts),
                'modeselectorform' => $this->get_display_mode_selector_html($displaymode, $user),
                'subscribe' => null,
                'movediscussion' => null,
                'pindiscussion' => null,
                'exportdiscussion' => !empty($CFG->enableportfolios) ? $this->get_export_discussion_html($user) : null,
            ],
            'settingsselector' => true,
        ]);

        $navbuttons = $this->get_discussion_navigation_buttons();
        if ($navbuttons !== null) {
            $exporteddiscussion['navigationbuttons'] = $navbuttons;
        } else {
            $exporteddiscussion['navigationbuttons'] = false;
            $exporteddiscussion['html']['neighbourlinks'] = $this->get_neighbour_links_html();
        }

        $capabilities = (array) $exporteddiscussion['capabilities'];

        if ($capabilities['move']) {
            $exporteddiscussion['html']['movediscussion'] = $this->get_move_discussion_html();
        }

        if (!empty($user->id)) {
            $loggedinuser = $entityfactory->get_author_from_stdClass($user);
            $exporteddiscussion['loggedinuser'] = [
                'firstname' => $loggedinuser->get_first_name(),
                'fullname' => $loggedinuser->get_full_name(),
                'profileimageurl' => ($urlfactory->get_author_profile_image_url($loggedinuser, null))->out(false),
            ];
        }

        $exporteddiscussion['throttlingwarningmsg'] = '';
        $cmrecord = $this->digestforum->get_course_module_record();
        if (($warningobj = digestforum_check_throttling($this->digestforumrecord, $cmrecord)) && $warningobj->canpost) {
            $throttlewarnnotification = (new notification(
                get_string($warningobj->errorcode, $warningobj->module, $warningobj->additional)
            ))->set_show_closebutton();
            $exporteddiscussion['throttlingwarningmsg'] = $throttlewarnnotification->get_message();
        }

        if ($this->displaymode === DFORUM_MODE_NESTED_V2) {
            $template = 'mod_digestforum/digestforum_discussion_nested_v2';
        } else {
            $template = 'mod_digestforum/digestforum_discussion';
        }

        return $this->renderer->render_from_template($template, $exporteddiscussion);
    }

    /**
     * Get the groups details for all groups available to the digestforum.
     *
     * @return  stdClass[]
     */
    private function get_groups_available_in_digestforum(): array {
        $course = $this->digestforum->get_course_record();
        $coursemodule = $this->digestforum->get_course_module_record();

        return groups_get_all_groups($course->id, 0, $coursemodule->groupingid);
    }

    /**
     * Get the exported discussion.
     *
     * @param stdClass $user The user viewing the discussion
     * @return array
     */
    private function get_exported_discussion(stdClass $user): array {
        $discussionexporter = $this->exporterfactory->get_discussion_exporter(
            $user,
            $this->digestforum,
            $this->discussion,
            $this->get_groups_available_in_digestforum()
        );

        return (array) $discussionexporter->export($this->renderer);
    }

    /**
     * Get the HTML for the display mode selector.
     *
     * @param int $displaymode The current display mode
     * @param stdClass $user The current user
     * @return string
     */
    private function get_display_mode_selector_html(int $displaymode, stdClass $user): string {
        $baseurl = $this->baseurl;
        $select = new single_select(
            $baseurl,
            'mode',
            digestforum_get_layout_modes(get_user_preferences('digestforum_useexperimentalui', false, $user)),
            $displaymode,
            null,
            'mode'
        );
        $select->set_label(get_string('displaymode', 'digestforum'), ['class' => 'accesshide']);

        return $this->renderer->render($select);
    }

    /**
     * Get the HTML to render the move discussion selector and button.
     *
     * @return string
     */
    private function get_move_discussion_html(): ?string {
        global $DB;

        $digestforum = $this->digestforum;
        $discussion = $this->discussion;
        $courseid = $digestforum->get_course_id();

        // Popup menu to move discussions to other digestforums. The discussion in a
        // single discussion digestforum can't be moved.
        $modinfo = get_fast_modinfo($courseid);
        if (isset($modinfo->instances['digestforum'])) {
            $digestforummenu = [];
            // Check digestforum types and eliminate simple discussions.
            $digestforumcheck = $DB->get_records('digestforum', ['course' => $courseid], '', 'id, type');
            foreach ($modinfo->instances['digestforum'] as $digestforumcm) {
                if (
                    !$digestforumcm->uservisible || !has_capability(
                        'mod/digestforum:startdiscussion',
                        context_module::instance($digestforumcm->id)
                    )
                ) {
                    continue;
                }
                $section = $digestforumcm->sectionnum;
                $sectionname = get_section_name($courseid, $section);
                if (empty($digestforummenu[$section])) {
                    $digestforummenu[$section] = [$sectionname => []];
                }
                $digestforumidcompare = $digestforumcm->instance != $digestforum->get_id();
                $digestforumtypecheck = $digestforumcheck[$digestforumcm->instance]->type !== 'single';

                if ($digestforumidcompare && $digestforumtypecheck) {
                    $url = "/mod/digestforum/discuss.php?d={$discussion->get_id()}&move=$digestforumcm->instance" .
                        "&sesskey=" . sesskey();
                    $digestforummenu[$section][$sectionname][$url] = format_string($digestforumcm->name);
                }
            }
            if (!empty($digestforummenu)) {
                $html = '<div class="movediscussionoption">';

                $movebutton = get_string('move');
                if ($this->displaymode === DFORUM_MODE_NESTED_V2) {
                    // Move discussion selector will be rendered on the settings drawer. We won't output the button in this mode.
                    $movebutton = null;
                }
                $select = new url_select(
                    $digestforummenu,
                    '',
                    ['/mod/digestforum/discuss.php?d=' . $discussion->get_id() => get_string(
                        "movethisdiscussionto",
                        "digestforum",
                    )],
                    'digestforummenu',
                    $movebutton
                );
                $select->set_label(get_string('movethisdiscussionlabel', 'mod_digestforum'), [
                    'class' => 'visually-hidden',
                ]);
                $html .= $this->renderer->render($select);
                $html .= "</div>";
                return $html;
            }
        }

        return null;
    }

    /**
     * Get the HTML to render the export discussion button.
     *
     * @param   stdClass $user The user viewing the discussion
     * @return  string|null
     */
    private function get_export_discussion_html(stdClass $user): ?string {
        global $CFG;

        if (!$this->capabilitymanager->can_export_discussions($user)) {
            return null;
        }

        $button = new \portfolio_add_button();
        $button->set_callback_options(
            'digestforum_portfolio_caller',
            ['discussionid' => $this->discussion->get_id()],
            'mod_digestforum',
        );
        $button = $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportdiscussion', 'mod_digestforum'));
        return $button ?: null;
    }

    /**
     * Get a list of notification HTML to render in the page.
     *
     * @param stdClass $user The user viewing the discussion
     * @return string[]
     */
    private function get_notifications($user): array {
        $notifications = $this->notifications;
        $discussion = $this->discussion;
        $digestforum = $this->digestforum;

        if ($digestforum->is_cutoff_date_reached()) {
            $notifications[] = (new notification(
                get_string('cutoffdatereached', 'digestforum'),
                notification::NOTIFY_INFO
            ))->set_show_closebutton();
        } else if ($digestforum->get_type() != 'single') {
            // Due date is already shown at the top of the page for single simple discussion digestforums.
            if ($digestforum->is_due_date_reached()) {
                $notifications[] = (new notification(
                    get_string('thisdigestforumisdue', 'digestforum', userdate($digestforum->get_due_date())),
                    notification::NOTIFY_INFO
                ))->set_show_closebutton();
            } else if ($digestforum->has_due_date()) {
                $notifications[] = (new notification(
                    get_string('thisdigestforumhasduedate', 'digestforum', userdate($digestforum->get_due_date())),
                    notification::NOTIFY_INFO
                ))->set_show_closebutton();
            }
        }

        if ($digestforum->is_discussion_locked($discussion)) {
            $notifications[] = (new notification(
                get_string('discussionlocked', 'digestforum'),
                notification::NOTIFY_INFO
            ))
                ->set_extra_classes(['discussionlocked'])
                ->set_show_closebutton();
        }

        if ($digestforum->get_type() == 'qanda') {
            if ($this->capabilitymanager->must_post_before_viewing_discussion($user, $discussion)) {
                $notifications[] = (new notification(
                    get_string('qandanotify', 'digestforum')
                ))->set_show_closebutton(true)->set_extra_classes(['mt-3']);
            }
        }

        if ($digestforum->has_blocking_enabled()) {
            $notifications[] = (new notification(
                get_string('thisdigestforumisthrottled', 'digestforum', [
                    'blockafter' => $digestforum->get_block_after(),
                    'blockperiod' => get_string('secondstotime' . $digestforum->get_block_period()),
                ]),
                notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        return array_map(function ($notification) {
            return $notification->export_for_template($this->renderer);
        }, $notifications);
    }

    /**
     * Get HTML to display the neighbour links.
     *
     * @return string
     */
    private function get_neighbour_links_html(): string {
        $digestforum = $this->digestforum;
        $coursemodule = $digestforum->get_course_module_record();
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $this->discussionrecord, $this->digestforumrecord);
        return $this->renderer->neighbouring_discussion_navigation($neighbours['prev'], $neighbours['next']);
    }

    /**
     * Get the discussion navigation element.
     *
     * Returns an array of data for the discussion navigation template.
     * This includes the previous and next discussion links if they exist.
     * If the display mode is nested v2, this function returns null as the navigation is not compatible with that display mode.
     *
     * @return array|null context for the discussion navigation template
     */
    public function get_discussion_navigation_buttons(): ?array {
        // Back to the legacy renderer if we're in nested v2 mode as the navigation is not compatible with this display mode.
        if ($this->displaymode === DFORUM_MODE_NESTED_V2) {
            return null;
        }
        $digestforum = $this->digestforum;
        $coursemodule = $digestforum->get_course_module_record();
        $neighbours = digestforum_get_discussion_neighbours($coursemodule, $this->discussionrecord, $this->digestforumrecord);

        $buttons = [];
        foreach ($neighbours as $key => $neighbour) {
            $button = [
                'type' => $key,
                'buttonicon' => $key === 'prev' ? 'i/previous' : 'i/next',
                'url' => '#',
                'title' => get_string($key . 'discussion', 'mod_digestforum'),
                'arialabel' => get_string($key . 'discussion', 'mod_digestforum'),
                'disabled' => true,
            ];
            if ($neighbour) {
                $url = new moodle_url('/mod/digestforum/discuss.php', ['d' => $neighbour->id]);
                $name = format_string($neighbour->name);
                $button['url'] = $url->out(false);
                $button['title'] = get_string($key . 'discussiona', 'mod_digestforum', $name);
                $button['arialabel'] = get_string($key . 'discussiona', 'mod_digestforum', $name);
                $button['disabled'] = false;
            }
            $buttons[] = $button;
        }
        return ['navbuttons' => $buttons];
    }
}
