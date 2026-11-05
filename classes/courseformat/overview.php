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

namespace mod_digestforum\courseformat;

use cm_info;
use core\url;
use core_calendar\output\humandate;
use core\output\local\properties\text_align;
use core_courseformat\local\overview\overviewitem;
use core_courseformat\output\local\overview\overviewaction;

/**
 * Forum overview integration.
 *
 * @package    mod_digestforum
 * @copyright  2025 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview extends \core_courseformat\activityoverviewbase {
    /** @var \stdClass|null $digestforum The digestforum instance. */
    private ?\stdClass $digestforum;

    /** @var \stdClass|null $user The current user instance. */
    private ?\stdClass $user;

    /**
     * Constructor.
     *
     * @param cm_info $cm the course module instance.
     * @param \core\output\renderer_helper $rendererhelper the renderer helper.
     * @param \moodle_database $db the database helper.
     */
    public function __construct(
        cm_info $cm,
        /** @var \core\output\renderer_helper $rendererhelper the renderer helper */
        protected readonly \core\output\renderer_helper $rendererhelper,
        /** @var \moodle_database The database instance */
        protected readonly \moodle_database $db,
    ) {
        global $USER;

        parent::__construct($cm);

        $this->user = $USER;
        $this->digestforum = $this->get_digestforum_instance();

        if ($this->digestforum) {
            // Fill the subscription cache for this course and user combination.
            \mod_digestforum\subscriptions::fill_subscription_cache_for_course($this->digestforum->course, $this->user->id);
        }
    }

    #[\Override]
    public function get_due_date_overview(): ?overviewitem {

        if ($this->is_teacher()) {
            return null;
        }

        $duedate = null;
        if (isset($this->cm->customdata['duedate'])) {
            $duedate = $this->cm->customdata['duedate'];
        }

        if (empty($duedate)) {
            return new overviewitem(
                name: get_string('duedate', 'mod_digestforum'),
                value: null,
                content: '-',
            );
        }
        return new overviewitem(
            name: get_string('duedate', 'mod_digestforum'),
            value: $duedate,
            content: humandate::create_from_timestamp($duedate),
        );
    }

    #[\Override]
    public function get_extra_overview_items(): array {
        if (empty($this->digestforum)) {
            // User cannot view this digestforum.
            return [];
        }

        return [
            'digestforumtype' => $this->get_extra_digestforumtype_overview(),
            'submitted' => $this->get_extra_track_overview(),
            'subscribed' => $this->get_extra_subscribed_overview(),
            'emaildigest' => $this->get_extra_emaildigest_overview(),
            'discussions' => $this->get_extra_discussions_overview(),
        ];
    }

    #[\Override]
    public function get_actions_overview(): ?overviewitem {

        if (empty($this->digestforum)) {
            // User cannot view this digestforum.
            return null;
        }

        $totaldiscussions = digestforum_count_discussions($this->digestforum, $this->cm, $this->course);
        $discussionposts = digestforum_count_discussion_replies($this->digestforum->id);
        $totalreplies = array_reduce($discussionposts, function ($sum, $post) {
            return $sum + $post->replies; // Add 1 to count the discussion post too.
        }, 0); // The '0' is the initial value of $sum.
        $totalreplies += $totaldiscussions; // Add the discussions to the replies count.

        $alertlabel = get_string('unreadposts', 'mod_digestforum');
        $unread = digestforum_tp_count_digestforum_unread_posts($this->cm, $this->course);
        $content = new overviewaction(
            url: new url('/mod/digestforum/view.php', ['id' => $this->cm->id]),
            text: $totalreplies,
            badgevalue: ($totalreplies > 0 && $unread > 0) ? $unread : null,
            badgetitle: ($totalreplies > 0 && $unread > 0) ? $alertlabel : null,
        );

        return new overviewitem(
            name: get_string('posts', 'mod_digestforum'),
            value: $totalreplies ? : '0',
            content: $content,
            alertcount: $unread,
            alertlabel: $alertlabel,
        );
    }

    /**
     * Get the digestforum type item.
     *
     * @return overviewitem|null The overview item (or null if the user cannot enable/disable track).
     */
    private function get_extra_digestforumtype_overview(): ?overviewitem {

        if (!$this->is_teacher()) {
            return null;
        }

        $alldigestforumtypesnames = digestforum_get_digestforum_types_all();

        return new overviewitem(
            name: get_string('digestforumtype', 'mod_digestforum'),
            value: $this->digestforum->type,
            content: $alldigestforumtypesnames[$this->digestforum->type],
        );
    }

    /**
     * Get the track toggle item.
     *
     * @return overviewitem The overview item.
     */
    private function get_extra_track_overview(): overviewitem {
        global $CFG;

        $disabled = true;
        $tracked = false;
        $label = null;
        if (
            (intval($this->digestforum->trackingtype) == DFORUM_TRACKING_FORCED)
            && ($CFG->digestforum_allowforcedreadtracking)
        ) {
            $label = get_string(
                'labelvalue',
                'core',
                [
                    'label' => get_string('trackdigestforum', 'mod_digestforum'),
                    'value' => get_string('trackingon', 'mod_digestforum'),
                ],
            );
            $disabled = true;
            $tracked = true;
        } else if (intval($this->digestforum->trackingtype) === DFORUM_TRACKING_OFF) {
            $label = get_string(
                'labelvalue',
                'core',
                [
                    'label' => get_string('trackdigestforum', 'mod_digestforum'),
                    'value' => get_string('trackingoff', 'mod_digestforum'),
                ],
            );
            $disabled = true;
        } else if (digestforum_tp_can_track_digestforums($this->digestforum)) {
            $tracked = digestforum_tp_is_tracked($this->digestforum);
            $disabled = false;
            $label = get_string('trackdigestforum', 'mod_digestforum');
        }

        $arialabel = $tracked
            ? get_string('untrackfordigestforum', 'mod_digestforum', $this->digestforum->name)
            : get_string('trackfordigestforum', 'mod_digestforum', $this->digestforum->name);

        $renderer = $this->rendererhelper->get_renderer('mod_digestforum');
        $extraattributes = [
            ['name' => 'data-type', 'value' => 'digestforum-track-toggle'],
            ['name' => 'data-action', 'value' => 'toggle'],
            ['name' => 'data-digestforumid', 'value' => $this->digestforum->id],
            ['name' => 'data-digestforumname', 'value' => $this->digestforum->name],
            ['name' => 'data-targetstate', 'value' => !$tracked],
            ['name' => 'aria-label', 'value' => $arialabel],
        ];
        $content = $renderer->render_from_template(
            'core/toggle',
            [
                'id' => 'digestforum-track-toggle-' . $this->digestforum->id,
                'checked' => $tracked,
                'disabled' => $disabled,
                'extraattributes' => $extraattributes,
                'label' => $label,
                'labelclasses' => 'visually-hidden',
            ],
        );

        $renderer->get_page()->requires->js_call_amd(
            'mod_digestforum/digestforum_overview_toggle',
            'init',
            ['#digestforum-track-toggle-' . $this->digestforum->id],
        );

        return new overviewitem(
            name: get_string('tracking', 'mod_digestforum'),
            value: $label,
            content: $content,
        );
    }

    /**
     * Get the subscribed toggle item.
     *
     * @return overviewitem The overview item.
     */
    private function get_extra_subscribed_overview(): overviewitem {

        $disabled = false;
        $subscribed = false;
        $label = null;
        if (\mod_digestforum\subscriptions::is_forcesubscribed($this->digestforum)) {
            $disabled = true;
            $subscribed = true;
            $label = get_string('subscribed', 'mod_digestforum');
        } else if (
            \mod_digestforum\subscriptions::subscription_disabled($this->digestforum)
            && !has_capability('mod/digestforum:managesubscriptions', $this->context)
        ) {
            $disabled = true;
            $label = get_string('unsubscribed', 'mod_digestforum');
        } else if (!is_enrolled($this->context, $this->user, '', true)) {
            $disabled = true;
            $label = get_string('unsubscribed', 'mod_digestforum');
        } else {
            $subscribed = \mod_digestforum\subscriptions::is_subscribed($this->user->id, $this->digestforum);
            if ($subscribed) {
                $label = get_string('unsubscribe', 'mod_digestforum');
            } else {
                $label = get_string('subscribe', 'mod_digestforum');
            }
        }

        $arialabel = $subscribed
            ? get_string('unsubscribefromdigestforum', 'mod_digestforum', $this->digestforum->name)
            : get_string('subscribetodigestforum', 'mod_digestforum', $this->digestforum->name);

        $renderer = $this->rendererhelper->get_renderer('mod_digestforum');
        $extraattributes = [
            ['name' => 'data-type', 'value' => 'digestforum-subscription-toggle'],
            ['name' => 'data-action', 'value' => 'toggle'],
            ['name' => 'data-digestforumid', 'value' => $this->digestforum->id],
            ['name' => 'data-digestforumname', 'value' => $this->digestforum->name],
            ['name' => 'data-username', 'value' => fullname($this->user)],
            ['name' => 'data-targetstate', 'value' => !$subscribed],
            ['name' => 'aria-label', 'value' => $arialabel],
        ];

        $content = $renderer->render_from_template(
            'core/toggle',
            [
                'id' => 'digestforum-subscription-toggle-' . $this->digestforum->id,
                'checked' => $subscribed,
                'disabled' => $disabled,
                'extraattributes' => $extraattributes,
                'label' => $label,
                'labelclasses' => 'visually-hidden',
            ],
        );

        $renderer->get_page()->requires->js_call_amd(
            'mod_digestforum/digestforum_overview_toggle',
            'init',
            ['#digestforum-subscription-toggle-' . $this->digestforum->id],
        );

        return new overviewitem(
            name: get_string('subscribed', 'mod_digestforum'),
            value: $label,
            content: $content,
        );
    }

    /**
     * Get the email digest selector item.
     *
     * @return overviewitem The overview item.
     */
    private function get_extra_emaildigest_overview(): overviewitem {

        $cansubscribe = \mod_digestforum\subscriptions::is_subscribable($this->digestforum);
        $canmanage = has_capability('mod/digestforum:managesubscriptions', $this->context);
        $issubscribed = \mod_digestforum\subscriptions::is_subscribed($this->user->id, $this->digestforum, null, $this->cm);
        if ($cansubscribe || $canmanage || $issubscribed) {
            if ($this->digestforum->maildigest === false) {
                $this->digestforum->maildigest = -1;
            }

            $renderer = $this->rendererhelper->get_renderer('mod_digestforum');
            $content = $renderer->render($renderer->render_digest_options($this->digestforum, $this->digestforum->maildigest));
        }
        $options = digestforum_get_user_digest_options();

        return new overviewitem(
            name: get_string('digesttype', 'mod_digestforum'),
            value: $options[$this->digestforum->maildigest] ?? '-',
            content: $content ?? '-',
        );
    }

    /**
     * Get the discussions item.
     *
     * @return overviewitem The overview item.
     */
    private function get_extra_discussions_overview(): overviewitem {

        $totaldiscussions = digestforum_count_discussions($this->digestforum, $this->cm, $this->course);

        return new overviewitem(
            name: get_string('discussions', 'mod_digestforum'),
            value: $totaldiscussions,
            content: $totaldiscussions,
            textalign: text_align::END,
        );
    }

    /**
     * Initialise the digestforum instance.
     *
     * @return \stdClass|null The digestforum instance or null if the user cannot view it.
     */
    private function get_digestforum_instance(): ?\stdClass {

        // User can't view this digestforum. Once the capability is renamed to digestforum:view, this check can be removed.
        if (!has_capability('mod/digestforum:viewdiscussion', $this->context)) {
            return null;
        }
        $digestforum = $this->cm->get_instance_record();

        // Initialise maildigest with the proper value.
        $digestforum->maildigest = $this->db->get_field(
            'digestforum_digests',
            'maildigest',
            ['userid' => $this->user->id, 'digestforum' => $digestforum->id],
        );

        return $digestforum;
    }

    /**
     * Whether the current user can be considered teacher or not (based on their capabilities).
     *
     * @return bool True if the user can be considered teacher; false otherwise.
     */
    private function is_teacher(): bool {
        return has_capability('mod/digestforum:rate', $this->context);
    }
}
