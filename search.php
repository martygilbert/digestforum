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

require_once('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT);                  // course id
$search = trim(optional_param('search', '', PARAM_NOTAGS));  // search string
$page = optional_param('page', 0, PARAM_INT);   // which page to show
$perpage = optional_param('perpage', 10, PARAM_INT);   // how many per page
$showform = optional_param('showform', 0, PARAM_INT);   // Just show the form

$user    = trim(optional_param('user', '', PARAM_NOTAGS));    // Names to search for
$userid  = trim(optional_param('userid', 0, PARAM_INT));      // UserID to search for
$digestforumid = trim(optional_param('digestforumid', 0, PARAM_INT));      // ForumID to search for
$subject = trim(optional_param('subject', '', PARAM_NOTAGS)); // Subject
$phrase  = trim(optional_param('phrase', '', PARAM_NOTAGS));  // Phrase
$words   = trim(optional_param('words', '', PARAM_NOTAGS));   // Words
$fullwords = trim(optional_param('fullwords', '', PARAM_NOTAGS)); // Whole words
$notwords = trim(optional_param('notwords', '', PARAM_NOTAGS));   // Words we don't want
$tags = optional_param_array('tags', [], PARAM_TEXT);

$timefromrestrict = optional_param('timefromrestrict', 0, PARAM_INT); // Use starting date
$fromday = optional_param('fromday', 0, PARAM_INT);      // Starting date
$frommonth = optional_param('frommonth', 0, PARAM_INT);      // Starting date
$fromyear = optional_param('fromyear', 0, PARAM_INT);      // Starting date
$fromhour = optional_param('fromhour', 0, PARAM_INT);      // Starting date
$fromminute = optional_param('fromminute', 0, PARAM_INT);      // Starting date
if ($timefromrestrict) {
    $calendartype = \core_calendar\type_factory::get_calendar_instance();
    $gregorianfrom = $calendartype->convert_to_gregorian($fromyear, $frommonth, $fromday);
    $datefrom = make_timestamp($gregorianfrom['year'], $gregorianfrom['month'], $gregorianfrom['day'], $fromhour, $fromminute);
} else {
    $datefrom = optional_param('datefrom', 0, PARAM_INT);      // Starting date
}

$timetorestrict = optional_param('timetorestrict', 0, PARAM_INT); // Use ending date
$today = optional_param('today', 0, PARAM_INT);      // Ending date
$tomonth = optional_param('tomonth', 0, PARAM_INT);      // Ending date
$toyear = optional_param('toyear', 0, PARAM_INT);      // Ending date
$tohour = optional_param('tohour', 0, PARAM_INT);      // Ending date
$tominute = optional_param('tominute', 0, PARAM_INT);      // Ending date
if ($timetorestrict) {
    $calendartype = \core_calendar\type_factory::get_calendar_instance();
    $gregorianto = $calendartype->convert_to_gregorian($toyear, $tomonth, $today);
    $dateto = make_timestamp($gregorianto['year'], $gregorianto['month'], $gregorianto['day'], $tohour, $tominute);
} else {
    $dateto = optional_param('dateto', 0, PARAM_INT);      // Ending date
}

$PAGE->set_pagelayout('standard');
$PAGE->set_url($FULLME); //TODO: this is very sloppy --skodak

if (empty($search)) {   // Check the other parameters instead
    if (!empty($words)) {
        $search .= ' '.$words;
    }
    if (!empty($userid)) {
        $search .= ' userid:'.$userid;
    }
    if (!empty($digestforumid)) {
        $search .= ' digestforumid:'.$digestforumid;
    }
    if (!empty($user)) {
        $search .= ' '.digestforum_clean_search_terms($user, 'user:');
    }
    if (!empty($subject)) {
        $search .= ' '.digestforum_clean_search_terms($subject, 'subject:');
    }
    if (!empty($fullwords)) {
        $search .= ' '.digestforum_clean_search_terms($fullwords, '+');
    }
    if (!empty($notwords)) {
        $search .= ' '.digestforum_clean_search_terms($notwords, '-');
    }
    if (!empty($phrase)) {
        $search .= ' "'.$phrase.'"';
    }
    if (!empty($datefrom)) {
        $search .= ' datefrom:'.$datefrom;
    }
    if (!empty($dateto)) {
        $search .= ' dateto:'.$dateto;
    }
    if (!empty($tags)) {
        $search .= ' tags:' . implode(',', $tags);
    }
    $individualparams = true;
} else {
    $individualparams = false;
}

if ($search) {
    $search = digestforum_clean_search_terms($search);
}

if (!$course = $DB->get_record('course', array('id'=>$id))) {
    print_error('invalidcourseid');
}

require_course_login($course);

$params = array(
    'context' => $PAGE->context,
    'other' => array('searchterm' => $search)
);

$event = \mod_digestforum\event\course_searched::create($params);
$event->trigger();

$strdigestforums = get_string("modulenameplural", "digestforum");
$strsearch = get_string("search", "digestforum");
$strsearchresults = get_string("searchresults", "digestforum");
$strpage = get_string("page");

if (!$search || $showform) {

    $PAGE->navbar->add($strdigestforums, new moodle_url('/mod/digestforum/index.php', array('id'=>$course->id)));
    $PAGE->navbar->add(get_string('advancedsearch', 'digestforum'));

    $PAGE->set_title($strsearch);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();

    digestforum_print_big_search_form($course);
    echo $OUTPUT->footer();
    exit;
}

/// We need to do a search now and print results

$searchterms = str_replace('digestforumid:', 'instance:', $search);
$searchterms = explode(' ', $searchterms);

$searchform = digestforum_search_form($course, $search);

$PAGE->navbar->add($strsearch, new moodle_url('/mod/digestforum/search.php', array('id'=>$course->id)));
$PAGE->navbar->add($strsearchresults);
if (!$posts = digestforum_search_posts($searchterms, $course->id, $page*$perpage, $perpage, $totalcount)) {
    $PAGE->set_title($strsearchresults);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($strdigestforums, 2);
    echo $OUTPUT->heading($strsearchresults, 3);
    echo $OUTPUT->heading(get_string("noposts", "digestforum"), 4);

    if (!$individualparams) {
        $words = $search;
    }

    digestforum_print_big_search_form($course);

    echo $OUTPUT->footer();
    exit;
}

//including this here to prevent it being included if there are no search results
require_once($CFG->dirroot.'/rating/lib.php');

//set up the ratings information that will be the same for all posts
$ratingoptions = new stdClass();
$ratingoptions->component = 'mod_digestforum';
$ratingoptions->ratingarea = 'post';
$ratingoptions->userid = $USER->id;
$ratingoptions->returnurl = $PAGE->url->out(false);
$rm = new rating_manager();

$PAGE->set_title($strsearchresults);
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();
echo '<div class="reportlink">';

$params = [
    'id'        => $course->id,
    'user'      => $user,
    'userid'    => $userid,
    'digestforumid'   => $digestforumid,
    'subject'   => $subject,
    'phrase'    => $phrase,
    'words'     => $words,
    'fullwords' => $fullwords,
    'notwords'  => $notwords,
    'dateto'    => $dateto,
    'datefrom'  => $datefrom,
    'showform'  => 1
];
$url    = new moodle_url("/mod/digestforum/search.php", $params);
foreach ($tags as $tag) {
    $url .= "&tags[]=$tag";
}
echo html_writer::link($url, get_string('advancedsearch', 'digestforum').'...');

echo '</div>';

echo $OUTPUT->heading($strdigestforums, 2);
echo $OUTPUT->heading("$strsearchresults: $totalcount", 3);

$url = new moodle_url('search.php', array('search' => $search, 'id' => $course->id, 'perpage' => $perpage));
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $url);

//added to implement highlighting of search terms found only in HTML markup
//fiedorow - 9/2/2005
$strippedsearch = str_replace('user:','',$search);
$strippedsearch = str_replace('subject:','',$strippedsearch);
$strippedsearch = str_replace('&quot;','',$strippedsearch);
$searchterms = explode(' ', $strippedsearch);    // Search for words independently
foreach ($searchterms as $key => $searchterm) {
    if (preg_match('/^\-/',$searchterm)) {
        unset($searchterms[$key]);
    } else {
        $searchterms[$key] = preg_replace('/^\+/','',$searchterm);
    }
}
$strippedsearch = implode(' ', $searchterms);    // Rebuild the string

foreach ($posts as $post) {

    // Replace the simple subject with the three items digestforum name -> thread name -> subject
    // (if all three are appropriate) each as a link.
    if (! $discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion))) {
        print_error('invaliddiscussionid', 'digestforum');
    }
    if (! $digestforum = $DB->get_record('digestforum', array('id' => "$discussion->digestforum"))) {
        print_error('invaliddigestforumid', 'digestforum');
    }

    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id)) {
        print_error('invalidcoursemodule');
    }

    $post->subject = highlight($strippedsearch, $post->subject);
    $discussion->name = highlight($strippedsearch, $discussion->name);

    $fullsubject = "<a href=\"view.php?f=$digestforum->id\">".format_string($digestforum->name,true)."</a>";
    if ($digestforum->type != 'single') {
        $fullsubject .= " -> <a href=\"discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a>";
        if ($post->parent != 0) {
            $fullsubject .= " -> <a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a>";
        }
    }

    $post->subject = $fullsubject;
    $post->subjectnoformat = true;

    //add the ratings information to the post
    //Unfortunately seem to have do this individually as posts may be from different digestforums
    if ($digestforum->assessed != RATING_AGGREGATE_NONE) {
        $modcontext = context_module::instance($cm->id);
        $ratingoptions->context = $modcontext;
        $ratingoptions->items = array($post);
        $ratingoptions->aggregate = $digestforum->assessed;//the aggregation method
        $ratingoptions->scaleid = $digestforum->scale;
        $ratingoptions->assesstimestart = $digestforum->assesstimestart;
        $ratingoptions->assesstimefinish = $digestforum->assesstimefinish;
        $postswithratings = $rm->get_ratings($ratingoptions);

        if ($postswithratings && count($postswithratings)==1) {
            $post = $postswithratings[0];
        }
    }

    // Identify search terms only found in HTML markup, and add a warning about them to
    // the start of the message text. However, do not do the highlighting here. digestforum_print_post
    // will do it for us later.
    $missing_terms = "";

    $options = new stdClass();
    $options->trusted = $post->messagetrust;
    $post->message = highlight($strippedsearch,
                    format_text($post->message, $post->messageformat, $options, $course->id),
                    0, '<fgw9sdpq4>', '</fgw9sdpq4>');

    foreach ($searchterms as $searchterm) {
        if (preg_match("/$searchterm/i",$post->message) && !preg_match('/<fgw9sdpq4>'.$searchterm.'<\/fgw9sdpq4>/i',$post->message)) {
            $missing_terms .= " $searchterm";
        }
    }

    $post->message = str_replace('<fgw9sdpq4>', '<span class="highlight">', $post->message);
    $post->message = str_replace('</fgw9sdpq4>', '</span>', $post->message);

    if ($missing_terms) {
        $strmissingsearchterms = get_string('missingsearchterms','digestforum');
        $post->message = '<p class="highlight2">'.$strmissingsearchterms.' '.$missing_terms.'</p>'.$post->message;
    }

    // Prepare a link to the post in context, to be displayed after the digestforum post.
    $fulllink = "<a href=\"discuss.php?d=$post->discussion#p$post->id\">".get_string("postincontext", "digestforum")."</a>";

    // Message is now html format.
    if ($post->messageformat != FORMAT_HTML) {
        $post->messageformat = FORMAT_HTML;
    }

    // Now pring the post.
    digestforum_print_post($post, $discussion, $digestforum, $cm, $course, false, false, false,
            $fulllink, '', -99, false);
}

echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $url);

echo $OUTPUT->footer();


 /**
  * Print a full-sized search form for the specified course.
  *
  * @param stdClass $course The Course that will be searched.
  * @return void The function prints the form.
  */
function digestforum_print_big_search_form($course) {
    global $PAGE, $words, $subject, $phrase, $user, $fullwords, $notwords, $datefrom, $dateto, $digestforumid, $tags;

    $renderable = new \mod_digestforum\output\big_search_form($course, $user);
    $renderable->set_words($words);
    $renderable->set_phrase($phrase);
    $renderable->set_notwords($notwords);
    $renderable->set_fullwords($fullwords);
    $renderable->set_datefrom($datefrom);
    $renderable->set_dateto($dateto);
    $renderable->set_subject($subject);
    $renderable->set_user($user);
    $renderable->set_digestforumid($digestforumid);
    $renderable->set_tags($tags);

    $output = $PAGE->get_renderer('mod_digestforum');
    echo $output->render($renderable);
}

/**
 * This function takes each word out of the search string, makes sure they are at least
 * two characters long and returns an string of the space-separated search
 * terms.
 *
 * @param string $words String containing space-separated strings to search for.
 * @param string $prefix String to prepend to the each token taken out of $words.
 * @return string The filtered search terms, separated by spaces.
 * @todo Take the hardcoded limit out of this function and put it into a user-specified parameter.
 */
function digestforum_clean_search_terms($words, $prefix='') {
    $searchterms = explode(' ', $words);
    foreach ($searchterms as $key => $searchterm) {
        if (strlen($searchterm) < 2) {
            unset($searchterms[$key]);
        } else if ($prefix) {
            $searchterms[$key] = $prefix.$searchterm;
        }
    }
    return trim(implode(' ', $searchterms));
}

 /**
  * Retrieve a list of the digestforums that this user can view.
  *
  * @param stdClass $course The Course to use.
  * @return array A set of formatted digestforum names stored against the digestforum id.
  */
function digestforum_menu_list($course)  {
    $menu = array();

    $modinfo = get_fast_modinfo($course);
    if (empty($modinfo->instances['digestforum'])) {
        return $menu;
    }

    foreach ($modinfo->instances['digestforum'] as $cm) {
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);
        if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
            continue;
        }
        $menu[$cm->instance] = format_string($cm->name);
    }

    return $menu;
}
