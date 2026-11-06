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
 * Displays a post, and all the posts below it.
 * If no post is given, displays all posts in a discussion
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$d      = required_param('d', PARAM_INT);                // Discussion ID.
$parent = optional_param('parent', 0, PARAM_INT);        // If set, then display this post and all children.
$mode   = optional_param('mode', 0, PARAM_INT);          // If set, changes the layout of the thread.
$move   = optional_param('move', 0, PARAM_INT);          // If set, moves this discussion to another digestforum.
$mark   = optional_param('mark', '', PARAM_ALPHA);       // Used for tracking read posts if user initiated.
$postid = optional_param('postid', 0, PARAM_INT);        // Used for tracking read posts if user initiated.
$pin    = optional_param('pin', -1, PARAM_INT);          // If set, pin or unpin this discussion.

$url = new moodle_url('/mod/digestforum/discuss.php', ['d' => $d]);
if ($parent !== 0) {
    $url->param('parent', $parent);
}
$PAGE->set_url($url);
// Core Boost styles forums through this body class.
$PAGE->add_body_class('path-mod-forum');

$vaultfactory = mod_digestforum\local\container::get_vault_factory();
$discussionvault = $vaultfactory->get_discussion_vault();
$discussion = $discussionvault->get_from_id($d);

if (!$discussion) {
    throw new \moodle_exception('errordiscussionnotfound', 'mod_digestforum');
}

$digestforumvault = $vaultfactory->get_digestforum_vault();
$digestforum = $digestforumvault->get_from_id($discussion->get_digestforum_id());

if (!$digestforum) {
    throw new \moodle_exception('errordigestforumnotfound', 'mod_digestforum');
}

$course = $digestforum->get_course_record();
$cm = $digestforum->get_course_module_record();

require_course_login($course, true, $cm);

$managerfactory = mod_digestforum\local\container::get_manager_factory();
$capabilitymanager = $managerfactory->get_capability_manager($digestforum);
$urlfactory = mod_digestforum\local\container::get_url_factory();

// Make sure we can render.
if (!$capabilitymanager->can_view_discussions($USER)) {
    throw new moodle_exception('noviewdiscussionspermission', 'mod_digestforum');
}

$datamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
$digestforumdatamapper = $datamapperfactory->get_digestforum_data_mapper();
$digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
$discussiondatamapper = $datamapperfactory->get_discussion_data_mapper();
$discussionrecord = $discussiondatamapper->to_legacy_object($discussion);
$discussionviewurl = $urlfactory->get_discussion_view_url_from_discussion($discussion);
// Set the activity record, to avoid additional calls to the db if the page getter is called.
$PAGE->set_activity_record($digestforumrecord);

// Add link to all discussions as supplementary content to go to the main digestforum page.
$PAGE->set_supplementary_content(
    new action_link(
        $urlfactory->get_digestforum_view_url_from_course_module_id($cm->id),
        get_string('gotoalldiscussions', 'mod_digestforum'),
    )
);

// Move this down fix for MDL-6926.
require_once($CFG->dirroot . '/mod/digestforum/lib.php');

$modcontext = $digestforum->get_context();

if (
    !empty($CFG->enablerssfeeds) &&
    !empty($CFG->digestforum_enablerssfeeds) &&
    $digestforum->get_rss_type() &&
    $digestforum->get_rss_articles()
) {
    require_once("$CFG->libdir/rsslib.php");

    $rsstitle = format_string(
        $course->shortname,
        true,
        ['context' => context_course::instance($course->id)]
    );
    $rsstitle .= ': ' . format_string($digestforum->get_name());
    rss_add_http_header($modcontext, 'mod_digestforum', $digestforumrecord, $rsstitle);
}

// Move discussion if requested.
if ($move > 0 && confirm_sesskey()) {
    $digestforumid = $digestforum->get_id();
    $discussionid = $discussion->get_id();
    $return = $discussionviewurl->out(false);

    if (!$digestforumto = $DB->get_record('digestforum', ['id' => $move])) {
        throw new \moodle_exception('cannotmovetonotexist', 'digestforum', $return);
    }

    if (!$capabilitymanager->can_move_discussions($USER)) {
        if ($digestforum->get_type() == 'single') {
            throw new \moodle_exception('cannotmovefromsingledigestforum', 'digestforum', $return);
        } else {
            throw new \moodle_exception(
                'nopermissions',
                'error',
                $return,
                get_capability_string('mod/digestforum:movediscussions'),
            );
        }
    }

    if ($digestforumto->type == 'single') {
        throw new \moodle_exception('cannotmovetosingledigestforum', 'digestforum', $return);
    }

    // Get target digestforum cm and check it is visible to current user.
    $modinfo = get_fast_modinfo($course);
    $digestforums = $modinfo->get_instances_of('digestforum');
    if (!array_key_exists($digestforumto->id, $digestforums)) {
        throw new \moodle_exception('cannotmovetonotfound', 'digestforum', $return);
    }

    $cmto = $digestforums[$digestforumto->id];
    if (!$cmto->uservisible) {
        throw new \moodle_exception('cannotmovenotvisible', 'digestforum', $return);
    }

    $destinationctx = context_module::instance($cmto->id);
    require_capability('mod/digestforum:startdiscussion', $destinationctx);

    if (!digestforum_move_attachments($discussionrecord, $digestforumid, $digestforumto->id)) {
        echo $OUTPUT->notification("Errors occurred while moving attachment directories - check your file permissions");
    }
    // For each subscribed user in this digestforum and discussion, copy over per-discussion subscriptions if required.
    $discussiongroup = $discussion->get_group_id() == -1 ? 0 : $discussion->get_group_id();
    $potentialsubscribers = \mod_digestforum\subscriptions::fetch_subscribed_users(
        $digestforumrecord,
        $discussiongroup,
        $modcontext,
        'u.id',
        true
    );

    // Pre-seed the subscribed_discussion caches.
    // Firstly for the digestforum being moved to.
    \mod_digestforum\subscriptions::fill_subscription_cache($digestforumto->id);
    // And also for the discussion being moved.
    \mod_digestforum\subscriptions::fill_subscription_cache($digestforumid);
    $subscriptionchanges = [];
    $subscriptiontime = time();
    foreach ($potentialsubscribers as $subuser) {
        $userid = $subuser->id;
        $targetsubscription = \mod_digestforum\subscriptions::is_subscribed($userid, $digestforumto, null, $cmto);
        $discussionsubscribed = \mod_digestforum\subscriptions::is_subscribed($userid, $digestforumrecord, $discussionid);
        $digestforumsubscribed = \mod_digestforum\subscriptions::is_subscribed($userid, $digestforumrecord);

        if ($digestforumsubscribed && !$discussionsubscribed && $targetsubscription) {
            // The user has opted out of this discussion and the move would cause them to receive notifications again.
            // Ensure they are unsubscribed from the discussion still.
            $subscriptionchanges[$userid] = \mod_digestforum\subscriptions::DFORUM_DISCUSSION_UNSUBSCRIBED;
        } else if (!$digestforumsubscribed && $discussionsubscribed && !$targetsubscription) {
            // The user has opted into this discussion and would otherwise not receive the subscription after the move.
            // Ensure they are subscribed to the discussion still.
            $subscriptionchanges[$userid] = $subscriptiontime;
        }
    }

    $DB->set_field('digestforum_discussions', 'digestforum', $digestforumto->id, ['id' => $discussionid]);
    $DB->set_field('digestforum_read', 'digestforumid', $digestforumto->id, ['discussionid' => $discussionid]);

    // Delete the existing per-discussion subscriptions and replace them with the newly calculated ones.
    $DB->delete_records('digestforum_discussion_subs', ['discussion' => $discussionid]);
    $newdiscussion = clone $discussionrecord;
    $newdiscussion->digestforum = $digestforumto->id;
    foreach ($subscriptionchanges as $userid => $preference) {
        if ($preference != \mod_digestforum\subscriptions::DFORUM_DISCUSSION_UNSUBSCRIBED) {
            // Users must have viewdiscussion to a discussion.
            if (has_capability('mod/digestforum:viewdiscussion', $destinationctx, $userid)) {
                \mod_digestforum\subscriptions::subscribe_user_to_discussion($userid, $newdiscussion, $destinationctx);
            }
        } else {
            \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($userid, $newdiscussion, $destinationctx);
        }
    }

    $params = [
        'context' => $destinationctx,
        'objectid' => $discussionid,
        'other' => [
            'fromdigestforumid' => $digestforumid,
            'todigestforumid' => $digestforumto->id,
        ],
    ];
    $event = \mod_digestforum\event\discussion_moved::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussionrecord);
    $event->add_record_snapshot('digestforum', $digestforumrecord);
    $event->add_record_snapshot('digestforum', $digestforumto);
    $event->trigger();

    // Delete the RSS files for the 2 digestforums to force regeneration of the feeds.
    require_once($CFG->dirroot . '/mod/digestforum/rsslib.php');
    digestforum_rss_delete_file($digestforumrecord);
    digestforum_rss_delete_file($digestforumto);

    redirect($return . '&move=-1&sesskey=' . sesskey());
}
// Pin or unpin discussion if requested.
if ($pin !== -1 && confirm_sesskey()) {
    if (!$capabilitymanager->can_pin_discussions($USER)) {
        throw new \moodle_exception('nopermissions', 'error', $return, get_capability_string('mod/digestforum:pindiscussions'));
    }

    $params = [
        'context' => $modcontext,
        'objectid' => $discussion->get_id(),
        'other' => ['digestforumid' => $digestforum->get_id()],
    ];

    switch ($pin) {
        case DFORUM_DISCUSSION_PINNED:
            // Pin the discussion and trigger discussion pinned event.
            digestforum_discussion_pin($modcontext, $digestforumrecord, $discussionrecord);
            break;
        case DFORUM_DISCUSSION_UNPINNED:
            // Unpin the discussion and trigger discussion unpinned event.
            digestforum_discussion_unpin($modcontext, $digestforumrecord, $discussionrecord);
            break;
        default:
            echo $OUTPUT->notification("Invalid value when attempting to pin/unpin discussion");
            break;
    }

    redirect($discussionviewurl->out(false));
}

// Trigger discussion viewed event.
digestforum_discussion_view($modcontext, $digestforumrecord, $discussionrecord);

unset($SESSION->fromdiscussion);

$saveddisplaymode = get_user_preferences('digestforum_displaymode', $CFG->digestforum_displaymode);

if ($mode) {
    $displaymode = $mode;
} else {
    $displaymode = $saveddisplaymode;
}

if (get_user_preferences('digestforum_useexperimentalui', false)) {
    if ($displaymode == DFORUM_MODE_NESTED) {
        $displaymode = DFORUM_MODE_NESTED_V2;
    }
} else {
    if ($displaymode == DFORUM_MODE_NESTED_V2) {
        $displaymode = DFORUM_MODE_NESTED;
    }
}

if ($displaymode != $saveddisplaymode) {
    set_user_preference('digestforum_displaymode', $displaymode);
}

if ($parent) {
    // If flat AND parent, then force nested display this time.
    if ($displaymode == DFORUM_MODE_FLATOLDEST || $displaymode == DFORUM_MODE_FLATNEWEST) {
        $displaymode = DFORUM_MODE_NESTED;
    }
} else {
    $parent = $discussion->get_first_post_id();
}

$postvault = $vaultfactory->get_post_vault();
if (!$post = $postvault->get_from_id($parent)) {
    throw new \moodle_exception("notexists", 'digestforum', "$CFG->wwwroot/mod/digestforum/view.php?f={$digestforum->get_id()}");
}

if (!$capabilitymanager->can_view_post($USER, $discussion, $post)) {
    throw new \moodle_exception(
        'noviewdiscussionspermission',
        'digestforum',
        "$CFG->wwwroot/mod/digestforum/view.php?id={$digestforum->get_id()}",
    );
}

$istracked = digestforum_tp_is_tracked($digestforumrecord, $USER);
if ($mark == 'read' || $mark == 'unread') {
    if ($CFG->digestforum_usermarksread && digestforum_tp_can_track_digestforums($digestforumrecord) && $istracked) {
        if ($mark == 'read') {
            digestforum_tp_add_read_record($USER->id, $postid);
        } else {
            // Unread.
            digestforum_tp_delete_read_records($USER->id, $postid);
        }
    }
}

$searchform = digestforum_search_form($course);

$digestforumnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
if (empty($digestforumnode)) {
    $digestforumnode = $PAGE->navbar;
} else {
    $digestforumnode->make_active();
}
$node = $digestforumnode->add(format_string($discussion->get_name()), $discussionviewurl);
$node->display = false;
if ($node && $post->get_id() != $discussion->get_first_post_id()) {
    $node->add(format_string($post->get_subject()), $PAGE->url);
}

$isnestedv2displaymode = $displaymode == DFORUM_MODE_NESTED_V2;
$PAGE->set_title("$course->shortname: " . format_string($discussion->get_name()));
$PAGE->set_heading($course->fullname);
$PAGE->set_secondary_active_tab('modulepage');
$PAGE->activityheader->disable();
if ($isnestedv2displaymode) {
    $PAGE->add_body_class('nested-v2-display-mode reset-style');
    $settingstrigger = $OUTPUT->render_from_template('mod_digestforum/settings_drawer_trigger', null);
    $PAGE->add_header_action($settingstrigger);
} else {
    $PAGE->add_header_action(digestforum_search_form($course));
}

echo $OUTPUT->header();

$rendererfactory = mod_digestforum\local\container::get_renderer_factory();
$discussionrenderer = $rendererfactory->get_discussion_renderer($digestforum, $discussion, $displaymode);
$orderpostsby = $displaymode == DFORUM_MODE_FLATNEWEST ? 'created DESC' : 'created ASC';
$replies = $postvault->get_replies_to_post($USER, $post, $capabilitymanager->can_view_any_private_reply($USER), $orderpostsby);

if (!$isnestedv2displaymode) {
    if (!$PAGE->has_secondary_navigation()) {
        echo $OUTPUT->heading(format_string($digestforum->get_name()), 2);
    }
    $headinglevel = $PAGE->activityheader->get_heading_level();

    // Output discussion name and discussion navigation buttons.
    echo $OUTPUT->container_start('discussionheader d-flex align-items-end pb-3 mb-5 border-bottom');
    echo $OUTPUT->heading(format_string($discussion->get_name()), $headinglevel, 'discussionname me-auto');
    echo $OUTPUT->render_from_template(
        'mod_digestforum/digestforum_discussion_navigation',
        $discussionrenderer->get_discussion_navigation_buttons(),
    );
    echo $OUTPUT->container_end();
}

if ($move == -1 && confirm_sesskey()) {
    $digestforumname = format_string($digestforum->get_name(), true);
    echo $OUTPUT->notification(get_string('discussionmoved', 'digestforum', $digestforumname), 'notifysuccess');
}

echo $discussionrenderer->render($USER, $post, $replies);
echo $OUTPUT->footer();

if ($istracked && !$CFG->digestforum_usermarksread) {
    if ($displaymode == DFORUM_MODE_THREADED) {
        digestforum_tp_add_read_record($USER->id, $post->get_id());
    } else {
        $postids = array_map(function ($post) {
            return $post->get_id();
        }, array_merge([$post], array_values($replies)));
        digestforum_tp_mark_posts_read($USER, $postids);
    }
}
