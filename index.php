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
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/digestforum/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all digestforums

$url = new moodle_url('/mod/digestforum/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = context_course::instance($course->id);


unset($SESSION->fromdiscussion);

$params = array(
    'context' => context_course::instance($course->id)
);
$event = \mod_digestforum\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strdigestforums       = get_string('digestforums', 'digestforum');
$strdigestforum        = get_string('digestforum', 'digestforum');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'digestforum');
$strsubscribed   = get_string('subscribed', 'digestforum');
$strunreadposts  = get_string('unreadposts', 'digestforum');
$strtracking     = get_string('tracking', 'digestforum');
$strmarkallread  = get_string('markallread', 'digestforum');
$strtrackdigestforum   = get_string('trackdigestforum', 'digestforum');
$strnotrackdigestforum = get_string('notrackdigestforum', 'digestforum');
$strsubscribe    = get_string('subscribe', 'digestforum');
$strunsubscribe  = get_string('unsubscribe', 'digestforum');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');
$stremaildigest  = get_string('emaildigest');

$searchform = digestforum_search_form($course);

// Retrieve the list of digestforum digest options for later.
$digestoptions = digestforum_get_user_digest_options();
$digestoptions_selector = new single_select(new moodle_url('/mod/digestforum/maildigest.php',
    array(
        'backtoindex' => 1,
    )),
    'maildigest',
    $digestoptions,
    null,
    '');
$digestoptions_selector->method = 'post';

// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strdigestforum, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = digestforum_tp_can_track_digestforums()) {
    $untracked = digestforum_tp_get_untracked_digestforums($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

// Fill the subscription cache for this course and user combination.
\mod_digestforum\subscriptions::fill_subscription_cache_for_course($course->id, $USER->id);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_digestforum');
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->digestforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->digestforum_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();

// Parse and organise all the digestforums.  Most digestforums are course modules but
// some special ones are not.  These get placed in the general digestforums
// category with the digestforums in section 0.

$digestforums = $DB->get_records_sql("
    SELECT f.*,
           d.maildigest
      FROM {digestforum} f
 LEFT JOIN {digestforum_digests} d ON d.digestforum = f.id AND d.userid = ?
     WHERE f.course = ?
    ", array($USER->id, $course->id));

$generaldigestforums  = array();
$learningdigestforums = array();
$modinfo = get_fast_modinfo($course);

foreach ($modinfo->get_instances_of('digestforum') as $digestforumid=>$cm) {
    if (!$cm->uservisible or !isset($digestforums[$digestforumid])) {
        continue;
    }

    $digestforum = $digestforums[$digestforumid];

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($digestforum->type == 'news' or $digestforum->type == 'social') {
        $generaldigestforums[$digestforum->id] = $digestforum;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generaldigestforums[$digestforum->id] = $digestforum;

    } else {
        $learningdigestforums[$digestforum->id] = $digestforum;
    }
}

// Do course wide subscribe/unsubscribe if requested
if (!is_null($subscribe)) {
    if (isguestuser() or !$can_subscribe) {
        // There should not be any links leading to this place, just redirect.
        redirect(
                new moodle_url('/mod/digestforum/index.php', array('id' => $id)),
                get_string('subscribeenrolledonly', 'digestforum'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
    }
    // Can proceed now, the user is not guest and is enrolled
    foreach ($modinfo->get_instances_of('digestforum') as $digestforumid=>$cm) {
        $digestforum = $digestforums[$digestforumid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/digestforum:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/digestforum:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!\mod_digestforum\subscriptions::is_forcesubscribed($digestforum)) {
            $subscribed = \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum, null, $cm);
            $canmanageactivities = has_capability('moodle/course:manageactivities', $coursecontext, $USER->id);
            if (($canmanageactivities || \mod_digestforum\subscriptions::is_subscribable($digestforum)) && $subscribe && !$subscribed && $cansub) {
                \mod_digestforum\subscriptions::subscribe_user($USER->id, $digestforum, $modcontext, true);
            } else if (!$subscribe && $subscribed) {
                \mod_digestforum\subscriptions::unsubscribe_user($USER->id, $digestforum, $modcontext, true);
            }
        }
    }
    $returnto = digestforum_go_back_to(new moodle_url('/mod/digestforum/index.php', array('id' => $course->id)));
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
    if ($subscribe) {
        redirect(
                $returnto,
                get_string('nowallsubscribed', 'digestforum', $shortname),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
    } else {
        redirect(
                $returnto,
                get_string('nowallunsubscribed', 'digestforum', $shortname),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
    }
}

/// First, let's process the general digestforums and build up a display

if ($generaldigestforums) {
    foreach ($generaldigestforums as $digestforum) {
        $cm      = $modinfo->instances['digestforum'][$digestforum->id];
        $context = context_module::instance($cm->id);

        $count = digestforum_count_discussions($digestforum, $cm, $course);

        if ($usetracking) {
            if ($digestforum->trackingtype == DFORUM_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$digestforum->id])) {
                        $unreadlink  = '-';
                } else if ($unread = digestforum_tp_count_digestforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$digestforum->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $digestforum->id.'&amp;mark=read&amp;sesskey=' . sesskey() . '"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if (($digestforum->trackingtype == DFORUM_TRACKING_FORCED) && ($CFG->digestforum_allowforcedreadtracking)) {
                    $trackedlink = $stryes;
                } else if ($digestforum->trackingtype === DFORUM_TRACKING_OFF || ($USER->trackdigestforums == 0)) {
                    $trackedlink = '-';
                } else {
                    $aurl = new moodle_url('/mod/digestforum/settracking.php', array(
                            'id' => $digestforum->id,
                            'sesskey' => sesskey(),
                        ));
                    if (!isset($untracked[$digestforum->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackdigestforum));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackdigestforum));
                    }
                }
            }
        }

        $digestforum->intro = shorten_text(format_module_intro('digestforum', $digestforum, $cm->id), $CFG->digestforum_shortpost);
        $digestforumname = format_string($digestforum->name, true);

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $digestforumlink = "<a href=\"view.php?f=$digestforum->id\" $style>".format_string($digestforum->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$digestforum->id\" $style>".$count."</a>";

        $row = array ($digestforumlink, $digestforum->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            $row[] = digestforum_get_subscribe_link($digestforum, $context, array('subscribed' => $stryes,
                    'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                    'cantsubscribe' => '-'), false, false, true);

            $digestoptions_selector->url->param('id', $digestforum->id);
            if ($digestforum->maildigest === null) {
                $digestoptions_selector->selected = -1;
            } else {
                $digestoptions_selector->selected = $digestforum->maildigest;
            }
            $row[] = $OUTPUT->render($digestoptions_selector);
        }

        //If this digestforum has RSS activated, calculate it
        if ($show_rss) {
            if ($digestforum->rsstype and $digestforum->rssarticles) {
                //Calculate the tooltip text
                if ($digestforum->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'digestforum');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'digestforum');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_digestforum', $digestforum->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strdigestforum, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

if ($usetracking) {
    $learningtable->head[] = $strunreadposts;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $strtracking;
    $learningtable->align[] = 'center';
}

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_digestforum');
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->digestforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->digestforum_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning digestforums

if ($course->id != SITEID) {    // Only real courses have learning digestforums
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningdigestforums) {
        $currentsection = '';
            foreach ($learningdigestforums as $digestforum) {
            $cm      = $modinfo->instances['digestforum'][$digestforum->id];
            $context = context_module::instance($cm->id);

            $count = digestforum_count_discussions($digestforum, $cm, $course);

            if ($usetracking) {
                if ($digestforum->trackingtype == DFORUM_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$digestforum->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = digestforum_tp_count_digestforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$digestforum->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $digestforum->id.'&amp;mark=read&sesskey=' . sesskey() . '"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if (($digestforum->trackingtype == DFORUM_TRACKING_FORCED) && ($CFG->digestforum_allowforcedreadtracking)) {
                        $trackedlink = $stryes;
                    } else if ($digestforum->trackingtype === DFORUM_TRACKING_OFF || ($USER->trackdigestforums == 0)) {
                        $trackedlink = '-';
                    } else {
                        $aurl = new moodle_url('/mod/digestforum/settracking.php', array('id'=>$digestforum->id));
                        if (!isset($untracked[$digestforum->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackdigestforum));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackdigestforum));
                        }
                    }
                }
            }

            $digestforum->intro = shorten_text(format_module_intro('digestforum', $digestforum, $cm->id), $CFG->digestforum_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $cm->sectionnum);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $digestforumname = format_string($digestforum->name,true);

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $digestforumlink = "<a href=\"view.php?f=$digestforum->id\" $style>".format_string($digestforum->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$digestforum->id\" $style>".$count."</a>";

            $row = array ($printsection, $digestforumlink, $digestforum->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                $row[] = digestforum_get_subscribe_link($digestforum, $context, array('subscribed' => $stryes,
                    'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                    'cantsubscribe' => '-'), false, false, true);

                $digestoptions_selector->url->param('id', $digestforum->id);
                if ($digestforum->maildigest === null) {
                    $digestoptions_selector->selected = -1;
                } else {
                    $digestoptions_selector->selected = $digestforum->maildigest;
                }
                $row[] = $OUTPUT->render($digestoptions_selector);
            }

            //If this digestforum has RSS activated, calculate it
            if ($show_rss) {
                if ($digestforum->rsstype and $digestforum->rssarticles) {
                    //Calculate the tolltip text
                    if ($digestforum->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'digestforum');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'digestforum');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_digestforum', $digestforum->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strdigestforums);
$PAGE->set_title("$course->shortname: $strdigestforums");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

// Show the subscribe all options only to non-guest, enrolled users
if (!isguestuser() && isloggedin() && $can_subscribe) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/digestforum/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'digestforum')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/digestforum/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'digestforum')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generaldigestforums) {
    echo $OUTPUT->heading(get_string('generaldigestforums', 'digestforum'), 2);
    echo html_writer::table($generaltable);
}

if ($learningdigestforums) {
    echo $OUTPUT->heading(get_string('learningdigestforums', 'digestforum'), 2);
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

