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
 * This script displays the digestforum summary report for the given parameters, within a user's capabilities.
 *
 * @package   digestforumreport_summary
 * @copyright 2019 Michael Hawkins <michaelh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../../../config.php");

if (isguestuser()) {
    throw new \moodle_exception('noguest');
}

$courseid = required_param('courseid', PARAM_INT);
$digestforumid = optional_param('digestforumid', 0, PARAM_INT);
$perpage = optional_param('perpage', \digestforumreport_summary\summary_table::DEFAULT_PER_PAGE, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$filters = [];
$pageurlparams = [
    'courseid' => $courseid,
    'perpage' => $perpage,
];

// Establish filter values.
$filters['groups'] = optional_param_array('filtergroups', [], PARAM_INT);
$filters['datefrom'] = optional_param_array('datefrom', ['enabled' => 0], PARAM_INT);
$filters['dateto'] = optional_param_array('dateto', ['enabled' => 0], PARAM_INT);

$modinfo = get_fast_modinfo($courseid);
$course = $modinfo->get_course();
$coursedigestforums = $modinfo->instances['digestforum'];
$cms = [];

// Determine which digestforums the user has access to in the course.
$accessalldigestforums = false;
$alldigestforumidsincourse = array_keys($coursedigestforums);
$digestforumsvisibletouser = [];
$digestforumselectoptions = [0 => get_string('digestforumselectcourseoption', 'digestforumreport_summary')];

foreach ($coursedigestforums as $coursedigestforumid => $coursedigestforum) {
    if ($coursedigestforum->uservisible) {
        $digestforumsvisibletouser[$coursedigestforumid] = $coursedigestforum;
        $digestforumselectoptions[$coursedigestforumid] = $coursedigestforum->get_formatted_name();
    }
}

if ($digestforumid) {
    if (!isset($digestforumsvisibletouser[$digestforumid])) {
        throw new \moodle_exception('A valid digestforum ID is required to generate a summary report.');
    }

    $filters['digestforums'] = [$digestforumid];
    $title = $digestforumsvisibletouser[$digestforumid]->get_formatted_name();
    $digestforumcm = $digestforumsvisibletouser[$digestforumid];
    $cms[] = $digestforumcm;

    require_login($courseid, false, $digestforumcm);
    $context = $digestforumcm->context;
    $canexport = !$download && has_capability('mod/digestforum:exportdigestforum', $context);
    $redirecturl = new moodle_url('/mod/digestforum/view.php', ['id' => $digestforumid]);
    $numdigestforums = 1;
    $pageurlparams['digestforumid'] = $digestforumid;
    $iscoursereport = false;
} else {
    // Course level report.
    require_login($courseid, false);

    $filters['digestforums'] = array_keys($digestforumsvisibletouser);

    // Fetch the digestforum CMs for the course.
    foreach ($digestforumsvisibletouser as $visibledigestforum) {
        $cms[] = $visibledigestforum;
    }

    $context = \context_course::instance($courseid);
    $title = $course->fullname;
    // Export currently only supports single digestforum exports.
    $canexport = false;
    $redirecturl = new moodle_url('/course/view.php', ['id' => $courseid]);
    $numdigestforums = count($digestforumsvisibletouser);
    $iscoursereport = true;

    // Specify whether user has access to all digestforums in the course.
    $accessalldigestforums = empty(array_diff($alldigestforumidsincourse, $filters['digestforums']));
}

$pageurl = new moodle_url('/mod/digestforum/report/summary/index.php', $pageurlparams);

$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('report');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->set_show_navigation_footer(false);
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('nodetitle', 'digestforumreport_summary'));

// Activate the secondary nav tab.
navigation_node::override_active_url(new moodle_url(
    '/mod/digestforum/report/summary/index.php',
    ['courseid' => $courseid, 'digestforumid' => $digestforumid]
));

$allowbulkoperations = !$download && !empty($CFG->messaging) && has_capability('moodle/course:bulkmessaging', $context);
$canseeprivatereplies = false;
$hasviewall = false;
$privatereplycapcount = 0;
$viewallcount = 0;
$canview = false;

foreach ($cms as $cm) {
    $digestforumcontext = $cm->context;

    // This capability is required in at least one of the given contexts to view any version of the report.
    if (has_capability('digestforumreport/summary:view', $digestforumcontext)) {
        $canview = true;
    }

    if (has_capability('mod/digestforum:readprivatereplies', $digestforumcontext)) {
        $privatereplycapcount++;
    }

    if (has_capability('digestforumreport/summary:viewall', $digestforumcontext)) {
        $viewallcount++;
    }
}

if (!$canview) {
    redirect($redirecturl);
}

// Only use private replies if user has that cap in all digestforums in the report.
if ($numdigestforums === $privatereplycapcount) {
    $canseeprivatereplies = true;
}

// Will only show all users if user has the cap for all digestforums in the report.
if ($numdigestforums === $viewallcount) {
    $hasviewall = true;
}

// Prepare and display the report.
$table = new \digestforumreport_summary\summary_table(
    $courseid,
    $filters,
    $allowbulkoperations,
    $canseeprivatereplies,
    $perpage,
    $canexport,
    $iscoursereport,
    $accessalldigestforums
);
$table->baseurl = $pageurl;

$eventparams = [
    'context' => $context,
    'other' => [
        'digestforumid' => $digestforumid,
        'hasviewall' => $hasviewall,
    ],
];

if ($download) {
    \digestforumreport_summary\event\report_downloaded::create($eventparams)->trigger();
    $table->download($download);
} else {
    \digestforumreport_summary\event\report_viewed::create($eventparams)->trigger();

    echo $OUTPUT->header();

    if (!empty($filters['groups'])) {
        \core\notification::info(get_string('viewsdisclaimer', 'digestforumreport_summary'));
    }

    // Allow switching to course report (or other digestforum user has access to).
    $reporturl = new moodle_url('/mod/digestforum/report/summary/index.php', ['courseid' => $courseid]);
    $digestforumselect = new single_select($reporturl, 'digestforumid', $digestforumselectoptions, $digestforumid, '');
    $digestforumselect->set_label(get_string('digestforumselectlabel', 'digestforumreport_summary'));
    echo $OUTPUT->render($digestforumselect);
    echo $OUTPUT->heading(get_string('nodetitle', 'digestforumreport_summary'), 2, 'pb-5 mt-3');

    // Render the report filters form.
    $renderer = $PAGE->get_renderer('digestforumreport_summary');

    unset($filters['digestforums']);
    echo $renderer->render_filters_form($course, $cms, $pageurl, $filters);
    $table->show_download_buttons_at([TABLE_P_BOTTOM]);
    echo $renderer->render_summary_table($table);
    echo $OUTPUT->footer();
}
