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
 * Displays the list of discussions in a digestforum.
 *
 * @package   mod_digestforum
 * @copyright 2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_digestforum\grades\digestforum_gradeitem;

require_once('../../config.php');

$managerfactory = mod_digestforum\local\container::get_manager_factory();
$legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
$vaultfactory = mod_digestforum\local\container::get_vault_factory();
$digestforumvault = $vaultfactory->get_digestforum_vault();
$discussionvault = $vaultfactory->get_discussion_vault();
$postvault = $vaultfactory->get_post_vault();
$discussionlistvault = $vaultfactory->get_discussions_in_digestforum_vault();

$cmid = optional_param('id', 0, PARAM_INT);
$digestforumid = optional_param('f', 0, PARAM_INT);
$mode = optional_param('mode', 0, PARAM_INT);
$showall = optional_param('showall', '', PARAM_INT);
$pageno = optional_param('page', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_CLEAN);
$pageno = optional_param('p', $pageno, PARAM_INT);
$pagesize = optional_param('s', $CFG->digestforum_manydiscussions ?? 0, PARAM_INT);
$sortorder = optional_param('o', null, PARAM_INT);

if (!$cmid && !$digestforumid) {
    throw new \moodle_exception('missingparameter');
}

if ($cmid) {
    $digestforum = $digestforumvault->get_from_course_module_id($cmid);
    if (empty($digestforum)) {
        throw new \moodle_exception('Unable to find digestforum with cmid ' . $cmid);
    }
} else {
    $digestforum = $digestforumvault->get_from_id($digestforumid);
    if (empty($digestforum)) {
        throw new \moodle_exception('Unable to find digestforum with id ' . $digestforumid);
    }
}

if (!empty($showall)) {
    // The user wants to see all discussions.
    $pageno = 0;
    $pagesize = 0;
}

$urlfactory = mod_digestforum\local\container::get_url_factory();
$capabilitymanager = $managerfactory->get_capability_manager($digestforum);

$url = $urlfactory->get_digestforum_view_url_from_digestforum($digestforum);
$PAGE->set_url($url);

$course = $digestforum->get_course_record();
$coursemodule = $digestforum->get_course_module_record();
$cm = \cm_info::create($coursemodule);

require_course_login($course, true, $cm);

$istypesingle = $digestforum->get_type() === 'single';
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

$PAGE->set_context($digestforum->get_context());
$PAGE->set_title($digestforum->get_name());
$PAGE->add_body_class('digestforumtype-' . $digestforum->get_type());
$PAGE->set_heading($course->fullname);
$PAGE->add_header_action(digestforum_search_form($course, $search));

if ($istypesingle && $displaymode == DFORUM_MODE_NESTED_V2) {
    $PAGE->add_body_class('nested-v2-display-mode reset-style');
    $settingstrigger = $OUTPUT->render_from_template('mod_digestforum/settings_drawer_trigger', null);
    $PAGE->add_header_action($settingstrigger);
}

if (empty($cm->visible) && !has_capability('moodle/course:viewhiddenactivities', $digestforum->get_context())) {
    redirect(
        $urlfactory->get_course_url_from_digestforum($digestforum),
        get_string('activityiscurrentlyhidden'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if (!$capabilitymanager->can_view_discussions($USER)) {
    redirect(
        $urlfactory->get_course_url_from_digestforum($digestforum),
        get_string('noviewdiscussionspermission', 'digestforum'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Mark viewed and trigger the course_module_viewed event.
$digestforumdatamapper = $legacydatamapperfactory->get_digestforum_data_mapper();
$digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
$PAGE->set_activity_record($digestforumrecord);
digestforum_view(
    $digestforumrecord,
    $digestforum->get_course_record(),
    $digestforum->get_course_module_record(),
    $digestforum->get_context()
);

// Return here if we post or set subscription etc.
$SESSION->fromdiscussion = qualified_me();

if (!empty($CFG->enablerssfeeds) && !empty($CFG->digestforum_enablerssfeeds) && $digestforum->get_rss_type() && $digestforum->get_rss_articles()) {
    require_once("{$CFG->libdir}/rsslib.php");

    $rsstitle = format_string($course->shortname, true, [
            'context' => context_course::instance($course->id),
        ]) . ': ' . format_string($digestforum->get_name());
    rss_add_http_header($digestforum->get_context(), 'mod_digestforum', $digestforumrecord, $rsstitle);
}
$activityheader = $PAGE->activityheader;
$pageheader = [];
if ($activityheader->is_title_allowed()) {
    $pageheader['title'] = format_string($digestforum->get_name());
}

// Fetch the current groupid.
$groupid = groups_get_activity_group($cm, true) ?: null;

if ($istypesingle || empty($digestforum->get_intro())) {
    $pageheader['description'] = '';
}
$activityheader->set_attrs($pageheader);

echo $OUTPUT->header();

$rendererfactory = mod_digestforum\local\container::get_renderer_factory();
// The elements for view action are rendered and added to the page.
echo digestforum_activity_actionbar($digestforum, $groupid, $course, $search);

if ($sortorder) {
    set_user_preference('digestforum_discussionlistsortorder', $sortorder);
}

$sortorder = get_user_preferences('digestforum_discussionlistsortorder', $discussionlistvault::SORTORDER_LASTPOST_DESC);

switch ($digestforum->get_type()) {
    case 'single':
        $digestforumgradeitem = digestforum_gradeitem::load_from_digestforum_entity($digestforum);
        if ($capabilitymanager->can_grade($USER)) {
            if ($digestforumgradeitem->is_grading_enabled()) {
                $groupid = groups_get_activity_group($cm, true) ?: null;
                $gradeobj = (object) [
                    'contextid' => $digestforum->get_context()->id,
                    'cmid' => $digestforum->get_course_module_record()->id,
                    'name' => format_string($digestforum->get_name()),
                    'courseid' => $course->id,
                    'coursename' => format_string($course->shortname),
                    'experimentaldisplaymode' => $displaymode == DFORUM_MODE_NESTED_V2,
                    'groupid' => $groupid,
                    'gradingcomponent' => $digestforumgradeitem->get_grading_component_name(),
                    'gradingcomponentsubtype' => $digestforumgradeitem->get_grading_component_subtype(),
                    'sendstudentnotifications' => $digestforum->should_notify_students_default_when_grade_for_digestforum(),
                    'gradeonlyactiveusers' => $digestforumgradeitem->should_grade_only_active_users(),
                ];
                echo $OUTPUT->render_from_template('mod_digestforum/grades/grade_button', $gradeobj);
            }
        } else {
            if ($digestforumgradeitem->is_grading_enabled()) {
                $groupid = groups_get_activity_group($cm, true) ?: null;
                $gradeobj = (object) [
                    'contextid' => $digestforum->get_context()->id,
                    'cmid' => $digestforum->get_course_module_record()->id,
                    'name' => format_string($digestforum->get_name()),
                    'courseid' => $course->id,
                    'coursename' => format_string($course->shortname),
                    'groupid' => $groupid,
                    'userid' => $USER->id,
                    'gradingcomponent' => $digestforumgradeitem->get_grading_component_name(),
                    'gradingcomponentsubtype' => $digestforumgradeitem->get_grading_component_subtype(),
                ];
                echo $OUTPUT->render_from_template('mod_digestforum/grades/view_grade_button', $gradeobj);
            }
        }
        $discussion = $discussionvault->get_last_discussion_in_digestforum($digestforum);
        $discussioncount = $discussionvault->get_count_discussions_in_digestforum($digestforum);
        $hasmultiplediscussions = $discussioncount > 1;
        $discussionsrenderer = $rendererfactory->get_single_discussion_list_renderer(
            $digestforum,
            $discussion,
            $hasmultiplediscussions,
            $displaymode
        );
        $post = $postvault->get_from_id($discussion->get_first_post_id());
        $orderpostsby = $displaymode == DFORUM_MODE_FLATNEWEST ? 'created DESC' : 'created ASC';
        $replies = $postvault->get_replies_to_post(
            $USER,
            $post,
            $capabilitymanager->can_view_any_private_reply($USER),
            $orderpostsby
        );
        echo $discussionsrenderer->render($USER, $post, $replies);

        if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforumrecord, $USER)) {
            $postids = array_map(function ($post) {
                return $post->get_id();
            }, array_merge([$post], array_values($replies)));
            digestforum_tp_mark_posts_read($USER, $postids);
        }
        break;
    case 'blog':
        $discussionsrenderer = $rendererfactory->get_blog_discussion_list_renderer($digestforum);
        // Blog digestforums always show discussions newest first.
        echo $discussionsrenderer->render(
            $USER,
            $cm,
            $groupid,
            $discussionlistvault::SORTORDER_CREATED_DESC,
            $pageno,
            $pagesize,
            null,
            false
        );

        if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforumrecord, $USER)) {
            $discussions = mod_digestforum_get_discussion_summaries($digestforum, $USER, $groupid, null, $pageno, $pagesize);
            $firstpostids = array_map(function ($discussion) {
                return $discussion->get_first_post()->get_id();
            }, array_values($discussions));
            digestforum_tp_mark_posts_read($USER, $firstpostids);
        }
        break;
    default:
        $discussionsrenderer = $rendererfactory->get_discussion_list_renderer($digestforum);
        echo $discussionsrenderer->render($USER, $cm, $groupid, $sortorder, $pageno, $pagesize, $displaymode, false);
}

echo $OUTPUT->footer();
