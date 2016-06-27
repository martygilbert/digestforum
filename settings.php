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
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/digestforum/lib.php');

    $settings->add(new admin_setting_configselect('digestforum_displaymode', get_string('displaymode', 'digestforum'),
                       get_string('configdisplaymode', 'digestforum'), DFORUM_MODE_NESTED, digestforum_get_layout_modes()));

    $settings->add(new admin_setting_configcheckbox('digestforum_replytouser', get_string('replytouser', 'digestforum'),
                       get_string('configreplytouser', 'digestforum'), 1));

    // Less non-HTML characters than this is short
    $settings->add(new admin_setting_configtext('digestforum_shortpost', get_string('shortpost', 'digestforum'),
                       get_string('configshortpost', 'digestforum'), 300, PARAM_INT));

    // More non-HTML characters than this is long
    $settings->add(new admin_setting_configtext('digestforum_longpost', get_string('longpost', 'digestforum'),
                       get_string('configlongpost', 'digestforum'), 600, PARAM_INT));

    // Number of discussions on a page
    $settings->add(new admin_setting_configtext('digestforum_manydiscussions', get_string('manydiscussions', 'digestforum'),
                       get_string('configmanydiscussions', 'digestforum'), 100, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $maxbytes = 0;
        if (isset($CFG->digestforum_maxbytes)) {
            $maxbytes = $CFG->digestforum_maxbytes;
        }
        $settings->add(new admin_setting_configselect('digestforum_maxbytes', get_string('maxattachmentsize', 'digestforum'),
                           get_string('configmaxbytes', 'digestforum'), 512000, get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes)));
    }

    // Default number of attachments allowed per post in all digestforums
    $settings->add(new admin_setting_configtext('digestforum_maxattachments', get_string('maxattachments', 'digestforum'),
                       get_string('configmaxattachments', 'digestforum'), 9, PARAM_INT));

    // Default Read Tracking setting.
    $options = array();
    $options[DFORUM_TRACKING_OPTIONAL] = get_string('trackingoptional', 'digestforum');
    $options[DFORUM_TRACKING_OFF] = get_string('trackingoff', 'digestforum');
    $options[DFORUM_TRACKING_FORCED] = get_string('trackingon', 'digestforum');
    $settings->add(new admin_setting_configselect('digestforum_trackingtype', get_string('trackingtype', 'digestforum'),
                       get_string('configtrackingtype', 'digestforum'), DFORUM_TRACKING_OPTIONAL, $options));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('digestforum_trackreadposts', get_string('trackdigestforum', 'digestforum'),
                       get_string('configtrackreadposts', 'digestforum'), 1));

    // Default whether user needs to mark a post as read.
    $settings->add(new admin_setting_configcheckbox('digestforum_allowforcedreadtracking', get_string('forcedreadtracking', 'digestforum'),
                       get_string('forcedreadtracking_desc', 'digestforum'), 0));

    // Default number of days that a post is considered old
    $settings->add(new admin_setting_configtext('digestforum_oldpostdays', get_string('oldpostdays', 'digestforum'),
                       get_string('configoldpostdays', 'digestforum'), 14, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('digestforum_usermarksread', get_string('usermarksread', 'digestforum'),
                       get_string('configusermarksread', 'digestforum'), 0));

    $options = array();
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d",$i);
    }
    // Default time (hour) to execute 'clean_read_records' cron
    $settings->add(new admin_setting_configselect('digestforum_cleanreadtime', get_string('cleanreadtime', 'digestforum'),
                       get_string('configcleanreadtime', 'digestforum'), 2, $options));

    // Default time (hour) to send digest email
    $settings->add(new admin_setting_configselect('digestforum_mailtime', get_string('digestforum_mailtime', 'digestforum'),
                       get_string('configdigestforum_mailtime', 'digestforum'), 7, $options));

    if (empty($CFG->enablerssfeeds)) {
        $options = array(0 => get_string('rssglobaldisabled', 'admin'));
        $str = get_string('configenablerssfeeds', 'digestforum').'<br />'.get_string('configenablerssfeedsdisabled2', 'admin');

    } else {
        $options = array(0=>get_string('no'), 1=>get_string('yes'));
        $str = get_string('configenablerssfeeds', 'digestforum');
    }
    $settings->add(new admin_setting_configselect('digestforum_enablerssfeeds', get_string('enablerssfeeds', 'admin'),
                       $str, 0, $options));

    if (!empty($CFG->enablerssfeeds)) {
        $options = array(
            0 => get_string('none'),
            1 => get_string('discussions', 'digestforum'),
            2 => get_string('posts', 'digestforum')
        );
        $settings->add(new admin_setting_configselect('digestforum_rsstype', get_string('rsstypedefault', 'digestforum'),
                get_string('configrsstypedefault', 'digestforum'), 0, $options));

        $options = array(
            0  => '0',
            1  => '1',
            2  => '2',
            3  => '3',
            4  => '4',
            5  => '5',
            10 => '10',
            15 => '15',
            20 => '20',
            25 => '25',
            30 => '30',
            40 => '40',
            50 => '50'
        );
        $settings->add(new admin_setting_configselect('digestforum_rssarticles', get_string('rssarticles', 'digestforum'),
                get_string('configrssarticlesdefault', 'digestforum'), 0, $options));
    }

    $settings->add(new admin_setting_configcheckbox('digestforum_enabletimedposts', get_string('timedposts', 'digestforum'),
                       get_string('configenabletimedposts', 'digestforum'), 1));
}

