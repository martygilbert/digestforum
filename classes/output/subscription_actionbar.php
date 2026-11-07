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

use moodle_url;
use renderer_base;
use url_select;
use renderable;
use templatable;

/**
 * Renders the subscribers page for this activity.
 *
 * @package   mod_digestforum
 * @copyright 2021 Sujith Haridasan <sujith@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscription_actionbar implements renderable, templatable {
    /** @var int course id */
    private $id;

    /** @var moodle_url */
    private $currenturl;

    /** @var \stdClass  */
    private $digestforum;

    /** @var int */
    private $edit;

    /**
     * subscription_actionbar constructor.
     *
     * @param int $id The digestforum id.
     * @param moodle_url $currenturl Current URL.
     * @param \stdClass $digestforum The digestforum object.
     * @param int $edit This argument decides to show view/manage subscribers view.
     */
    public function __construct(int $id, moodle_url $currenturl, \stdClass $digestforum, int $edit) {
        $this->id = $id;
        $this->currenturl = $currenturl;
        $this->digestforum = $digestforum;
        $this->edit = $edit;
    }

    /**
     * Create url select menu for subscription option
     *
     * @return url_select|null the url_select object
     */
    private function create_subscription_menu(): ?url_select {
        // When user is on manage subscription, we don't have to show the subscription selector.
        if ($this->edit === 1 && !\mod_digestforum\subscriptions::is_forcesubscribed($this->digestforum)) {
            return  null;
        }

        $sesskey = sesskey();
        $modeset = \mod_digestforum\subscriptions::get_subscription_mode($this->digestforum);
        $optionallink = new moodle_url(
            '/mod/digestforum/subscribe.php',
            ['id' => $this->id, 'mode' => DFORUM_CHOOSESUBSCRIBE, 'sesskey' => $sesskey, 'edit' => $this->edit]
        );
        $forcedlink = new moodle_url(
            '/mod/digestforum/subscribe.php',
            ['id' => $this->id, 'mode' => DFORUM_FORCESUBSCRIBE, 'sesskey' => $sesskey, 'edit' => $this->edit]
        );
        $autolink = new moodle_url(
            '/mod/digestforum/subscribe.php',
            ['id' => $this->id, 'mode' => DFORUM_INITIALSUBSCRIBE, 'sesskey' => $sesskey, 'edit' => $this->edit]
        );
        $disabledlink = new moodle_url(
            '/mod/digestforum/subscribe.php',
            ['id' => $this->id, 'mode' => DFORUM_DISALLOWSUBSCRIBE, 'sesskey' => $sesskey, 'edit' => $this->edit]
        );

        $menu = [
            $optionallink->out(false) => get_string('subscriptionoptional', 'digestforum'),
            $forcedlink->out(false) => get_string('subscriptionforced', 'digestforum'),
            $autolink->out(false) => get_string('subscriptionauto', 'digestforum'),
            $disabledlink->out(false) => get_string('subscriptiondisabled', 'digestforum'),
        ];

        switch ($modeset) {
            case DFORUM_CHOOSESUBSCRIBE:
                $set = get_string('subscriptionoptional', 'digestforum');
                break;
            case DFORUM_FORCESUBSCRIBE:
                $set = get_string('subscriptionforced', 'digestforum');
                break;
            case DFORUM_INITIALSUBSCRIBE:
                $set = get_string('subscriptionauto', 'digestforum');
                break;
            case DFORUM_DISALLOWSUBSCRIBE:
                $set = get_string('subscriptiondisabled', 'digestforum');
                break;
            default:
                throw new \moodle_exception(get_string('invalidforcesubscribe', 'digestforum'));
        }

        $menu = array_filter($menu, function ($key) use ($set) {
            if ($key !== $set) {
                return true;
            }
        });
        $urlselect = new url_select($menu, $this->currenturl, ['' => $set], 'selectsubscriptionoptions');
        $urlselect->set_label(get_string('subscriptionmode', 'mod_digestforum'), ['class' => 'me-1']);
        $urlselect->set_help_icon('subscriptionmode', 'mod_digestforum');
        $urlselect->class .= ' float-end';
        return $urlselect;
    }

    /**
     * Create view and manage subscribers select menu.
     *
     * @return url_select|null get url_select object.
     */
    private function create_view_manage_menu(): ?url_select {
        // If forced subscription is used then no need to show the view.
        if (\mod_digestforum\subscriptions::is_forcesubscribed($this->digestforum)) {
            return null;
        }

        $viewlink = new moodle_url('/mod/digestforum/subscribers.php', ['id' => $this->id, 'edit' => 'off']);
        $managelink = new moodle_url('/mod/digestforum/subscribers.php', ['id' => $this->id, 'edit' => 'on']);

        $menu = [
            $viewlink->out(false) => get_string('digestforum:viewsubscribers', 'digestforum'),
            $managelink->out(false) => get_string('managesubscriptionson', 'digestforum'),
        ];

        if ($this->edit === 0) {
            $this->currenturl = $viewlink;
        } else {
            $this->currenturl = $managelink;
        }
        $urlselect = new url_select($menu, $this->currenturl->out(false), null, 'selectviewandmanagesubscribers');
        $urlselect->set_label(get_string('subscribers', 'digestforum'), ['class' => 'accesshide']);
        return $urlselect;
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output The render_base object.
     * @return array data for template
     */
    public function export_for_template(renderer_base $output): array {
        $data = [];
        $subscribeoptionselect = $this->create_subscription_menu();
        $viewmanageselect = $this->create_view_manage_menu();

        if ($subscribeoptionselect) {
            $data['subscriptionoptions'] = $subscribeoptionselect->export_for_template($output);
        }
        if ($viewmanageselect) {
            $data['viewandmanageselect'] = $viewmanageselect->export_for_template($output);
        }
        return $data;
    }
}
