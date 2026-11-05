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
 * Forum Exporter.
 *
 * @package     mod_digestforum
 * @copyright   2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\exporters;

use mod_digestforum\local\entities\digestforum as digestforum_entity;
use mod_digestforum\local\exporters\post as post_exporter;
use core\external\exporter;
use renderer_base;
use stdClass;

/**
 * Forum class.
 *
 * @copyright   2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum extends exporter {
    /** @var digestforum_entity The entity relating to the digestforum being displayed */
    private $digestforum;

    /**
     * Constructor for the digestforum exporter.
     *
     * @param   digestforum_entity    $digestforum The digestforum being displayed
     * @param   array           $related The related objects
     */
    public function __construct(digestforum_entity $digestforum, $related = []) {
        $this->digestforum = $digestforum;
        return parent::__construct([], $related);
    }

    /**
     * Return the list of additional properties.
     *
     * @return array
     */
    protected static function define_other_properties() {
        return [
            'id' => ['type' => PARAM_INT],
            'name' => ['type' => PARAM_RAW],
            'state' => [
                'type' => [
                    'groupmode' => ['type' => PARAM_INT],
                    'gradingenabled' => ['type' => PARAM_BOOL],
                ],
            ],
            'userstate' => [
                'type' => [
                    'tracked' => ['type' => PARAM_INT],
                    'subscribed' => ['type' => PARAM_INT],
                ],
            ],
            'capabilities' => [
                'type' => [
                    'viewdiscussions' => ['type' => PARAM_BOOL],
                    'create' => ['type' => PARAM_BOOL],
                    'subscribe' => ['type' => PARAM_BOOL],
                    'grade' => ['type' => PARAM_BOOL],
                ],
            ],
            'urls' => [
                'type' => [
                    'create' => ['type' => PARAM_URL],
                    'markasread' => ['type' => PARAM_URL],
                    'view' => ['type' => PARAM_URL],
                    'sortrepliesasc' => ['type' => PARAM_URL],
                    'sortrepliesdesc' => ['type' => PARAM_URL],
                    'sortlastpostasc' => ['type' => PARAM_URL],
                    'sortlastpostdesc' => ['type' => PARAM_URL],
                    'sortcreatedasc' => ['type' => PARAM_URL],
                    'sortcreateddesc' => ['type' => PARAM_URL],
                    'sortdiscussionasc' => ['type' => PARAM_URL],
                    'sortdiscussiondesc' => ['type' => PARAM_URL],
                    'sortstarterasc' => ['type' => PARAM_URL],
                    'sortstarterdesc' => ['type' => PARAM_URL],
                    'sortgroupasc' => ['type' => PARAM_URL],
                    'sortgroupdesc' => ['type' => PARAM_URL],
                ],
            ],
        ];
    }

    /**
     * Get the additional values to inject while exporting.
     *
     * @param renderer_base $output The renderer.
     * @return array Keys are the property names, values are their values.
     */
    protected function get_other_values(renderer_base $output) {
        $capabilitymanager = $this->related['capabilitymanager'];
        $urlfactory = $this->related['urlfactory'];
        $user = $this->related['user'];
        $currentgroup = $this->related['currentgroup'];
        $vaultfactory = $this->related['vaultfactory'];
        $discussionvault = $vaultfactory->get_discussions_in_digestforum_vault();

        return [
            'id' => $this->digestforum->get_id(),
            'name' => $this->digestforum->get_name(),
            'state' => [
                'groupmode' => $this->digestforum->get_effective_group_mode(),
                'gradingenabled' => $this->digestforum->is_grading_enabled(),
            ],
            'userstate' => [
                'tracked' => (int) digestforum_tp_is_tracked($this->get_digestforum_record(), $this->related['user']),
                'subscribed' => (int) \mod_digestforum\subscriptions::is_subscribed(
                    $this->related['user']->id,
                    $this->get_digestforum_record(),
                ),
            ],
            'capabilities' => [
                'viewdiscussions' => $capabilitymanager->can_view_discussions($user),
                'create' => $capabilitymanager->can_create_discussions($user, $currentgroup),
                'selfenrol' => $capabilitymanager->can_self_enrol($user),
                'subscribe' => $capabilitymanager->can_subscribe_to_digestforum($user),
                'grade' => $capabilitymanager->can_grade($user),
            ],
            'urls' => [
                'create' => $urlfactory->get_discussion_create_url($this->digestforum)->out(false),
                'markasread' => $urlfactory->get_mark_all_discussions_as_read_url($this->digestforum)->out(false),
                'view' => $urlfactory->get_digestforum_view_url_from_digestforum($this->digestforum)->out(false),
                'sortrepliesasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_REPLIES_ASC
                )->out(false),
                'sortrepliesdesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_REPLIES_DESC
                )->out(false),
                'sortlastpostasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_LASTPOST_ASC
                )->out(false),
                'sortlastpostdesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_LASTPOST_DESC
                )->out(false),
                'sortcreatedasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_CREATED_ASC
                )->out(false),
                'sortcreateddesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_CREATED_DESC
                )->out(false),
                'sortdiscussionasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_DISCUSSION_ASC
                )->out(false),
                'sortdiscussiondesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_DISCUSSION_DESC
                )->out(false),
                'sortstarterasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_STARTER_ASC
                )->out(false),
                'sortstarterdesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_STARTER_DESC
                )->out(false),
                'sortgroupasc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_GROUP_ASC
                )->out(false),
                'sortgroupdesc' => $urlfactory->get_digestforum_view_url_from_digestforum(
                    $this->digestforum,
                    null,
                    $discussionvault::SORTORDER_GROUP_DESC
                )->out(false),
            ],
        ];
    }

    /**
     * Returns a list of objects that are related.
     *
     * @return array
     */
    protected static function define_related() {
        return [
            'legacydatamapperfactory' => 'mod_digestforum\local\factories\legacy_data_mapper',
            'capabilitymanager' => 'mod_digestforum\local\managers\capability',
            'urlfactory' => 'mod_digestforum\local\factories\url',
            'user' => 'stdClass',
            'currentgroup' => 'int?',
            'vaultfactory' => 'mod_digestforum\local\factories\vault',
        ];
    }

    /**
     * Get the legacy digestforum record for this digestforum.
     *
     * @return  stdClass
     */
    private function get_digestforum_record(): stdClass {
        $digestforumdbdatamapper = $this->related['legacydatamapperfactory']->get_digestforum_data_mapper();
        return $digestforumdbdatamapper->to_legacy_object($this->digestforum);
    }
}
