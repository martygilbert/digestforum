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
 * @package mod-digestform
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/digestform/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all digestforms

$url = new moodle_url('/mod/digestform/index.php', array('id'=>$id));
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

add_to_log($course->id, 'digestform', 'view digestforms', "index.php?id=$course->id");

$strdigestforms       = get_string('digestforms', 'digestform');
$strdigestform        = get_string('digestform', 'digestform');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'digestform');
$strsubscribed   = get_string('subscribed', 'digestform');
$strunreadposts  = get_string('unreadposts', 'digestform');
$strtracking     = get_string('tracking', 'digestform');
$strmarkallread  = get_string('markallread', 'digestform');
$strtrackdigestform   = get_string('trackdigestform', 'digestform');
$strnotrackdigestform = get_string('notrackdigestform', 'digestform');
$strsubscribe    = get_string('subscribe', 'digestform');
$strunsubscribe  = get_string('unsubscribe', 'digestform');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');

$searchform = digestform_search_form($course);


// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strdigestform, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = digestform_tp_can_track_digestforms()) {
    $untracked = digestform_tp_get_untracked_digestforms($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

$subscribed_digestforms = digestform_get_subscribed_digestforms($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->digestform_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->digestform_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();

// Parse and organise all the digestforms.  Most digestforms are course modules but
// some special ones are not.  These get placed in the general digestforms
// category with the digestforms in section 0.

$digestforms = $DB->get_records('digestform', array('course' => $course->id));

$generaldigestforms  = array();
$learningdigestforms = array();
$modinfo = get_fast_modinfo($course);

if (!isset($modinfo->instances['digestform'])) {
    $modinfo->instances['digestform'] = array();
}

foreach ($modinfo->instances['digestform'] as $digestformid=>$cm) {
    if (!$cm->uservisible or !isset($digestforms[$digestformid])) {
        continue;
    }

    $digestform = $digestforms[$digestformid];

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/digestform:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($digestform->type == 'news' or $digestform->type == 'social') {
        $generaldigestforms[$digestform->id] = $digestform;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generaldigestforms[$digestform->id] = $digestform;

    } else {
        $learningdigestforms[$digestform->id] = $digestform;
    }
}

// Do course wide subscribe/unsubscribe if requested
if (!is_null($subscribe)) {
    if (isguestuser() or !$can_subscribe) {
        // there should not be any links leading to this place, just redirect
        redirect(new moodle_url('/mod/digestform/index.php', array('id' => $id)), get_string('subscribeenrolledonly', 'digestform'));
    }
    // Can proceed now, the user is not guest and is enrolled
    foreach ($modinfo->instances['digestform'] as $digestformid=>$cm) {
        $digestform = $digestforms[$digestformid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/digestform:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/digestform:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!digestform_is_forcesubscribed($digestform)) {
            $subscribed = digestform_is_subscribed($USER->id, $digestform);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $digestform->forcesubscribe != DIGESTFORUM_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                digestform_subscribe($USER->id, $digestformid);
            } else if (!$subscribe && $subscribed) {
                digestform_unsubscribe($USER->id, $digestformid);
            }
        }
    }
    $returnto = digestform_go_back_to("index.php?id=$course->id");
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
    if ($subscribe) {
        add_to_log($course->id, 'digestform', 'subscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallsubscribed', 'digestform', $shortname), 1);
    } else {
        add_to_log($course->id, 'digestform', 'unsubscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallunsubscribed', 'digestform', $shortname), 1);
    }
}

/// First, let's process the general digestforms and build up a display

if ($generaldigestforms) {
    foreach ($generaldigestforms as $digestform) {
        $cm      = $modinfo->instances['digestform'][$digestform->id];
        $context = context_module::instance($cm->id);

        $count = digestform_count_discussions($digestform, $cm, $course);

        if ($usetracking) {
            if ($digestform->trackingtype == DIGESTFORUM_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$digestform->id])) {
                        $unreadlink  = '-';
                } else if ($unread = digestform_tp_count_digestform_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$digestform->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $digestform->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if ($digestform->trackingtype == DIGESTFORUM_TRACKING_ON) {
                    $trackedlink = $stryes;

                } else {
                    $aurl = new moodle_url('/mod/digestform/settracking.php', array('id'=>$digestform->id));
                    if (!isset($untracked[$digestform->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackdigestform));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackdigestform));
                    }
                }
            }
        }

        $digestform->intro = shorten_text(format_module_intro('digestform', $digestform, $cm->id), $CFG->digestform_shortpost);
        $digestformname = format_string($digestform->name, true);

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $digestformlink = "<a href=\"view.php?f=$digestform->id\" $style>".format_string($digestform->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$digestform->id\" $style>".$count."</a>";

        $row = array ($digestformlink, $digestform->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            if ($digestform->forcesubscribe != DIGESTFORUM_DISALLOWSUBSCRIBE) {
                $row[] = digestform_get_subscribe_link($digestform, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_digestforms);
            } else {
                $row[] = '-';
            }
        }

        //If this digestform has RSS activated, calculate it
        if ($show_rss) {
            if ($digestform->rsstype and $digestform->rssarticles) {
                //Calculate the tooltip text
                if ($digestform->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'digestform');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'digestform');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_digestform', $digestform->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strdigestform, $strdescription, $strdiscussions);
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
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->digestform_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->digestform_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning digestforms

if ($course->id != SITEID) {    // Only real courses have learning digestforms
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningdigestforms) {
        $currentsection = '';
            foreach ($learningdigestforms as $digestform) {
            $cm      = $modinfo->instances['digestform'][$digestform->id];
            $context = context_module::instance($cm->id);

            $count = digestform_count_discussions($digestform, $cm, $course);

            if ($usetracking) {
                if ($digestform->trackingtype == DIGESTFORUM_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$digestform->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = digestform_tp_count_digestform_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$digestform->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $digestform->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if ($digestform->trackingtype == DIGESTFORUM_TRACKING_ON) {
                        $trackedlink = $stryes;

                    } else {
                        $aurl = new moodle_url('/mod/digestform/settracking.php', array('id'=>$digestform->id));
                        if (!isset($untracked[$digestform->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackdigestform));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackdigestform));
                        }
                    }
                }
            }

            $digestform->intro = shorten_text(format_module_intro('digestform', $digestform, $cm->id), $CFG->digestform_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $cm->sectionnum);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $digestformname = format_string($digestform->name,true);

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $digestformlink = "<a href=\"view.php?f=$digestform->id\" $style>".format_string($digestform->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$digestform->id\" $style>".$count."</a>";

            $row = array ($printsection, $digestformlink, $digestform->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                if ($digestform->forcesubscribe != DIGESTFORUM_DISALLOWSUBSCRIBE) {
                    $row[] = digestform_get_subscribe_link($digestform, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_digestforms);
                } else {
                    $row[] = '-';
                }
            }

            //If this digestform has RSS activated, calculate it
            if ($show_rss) {
                if ($digestform->rsstype and $digestform->rssarticles) {
                    //Calculate the tolltip text
                    if ($digestform->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'digestform');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'digestform');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_digestform', $digestform->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strdigestforms);
$PAGE->set_title("$course->shortname: $strdigestforms");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

// Show the subscribe all options only to non-guest, enrolled users
if (!isguestuser() && isloggedin() && $can_subscribe) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/digestform/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'digestform')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/digestform/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'digestform')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generaldigestforms) {
    echo $OUTPUT->heading(get_string('generaldigestforms', 'digestform'));
    echo html_writer::table($generaltable);
}

if ($learningdigestforms) {
    echo $OUTPUT->heading(get_string('learningdigestforms', 'digestform'));
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

