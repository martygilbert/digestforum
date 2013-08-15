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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the restore steps that will be used by the restore_digestforum_activity_task
 */

/**
 * Structure step to restore one digestforum activity
 */
class restore_digestforum_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('digestforum', '/activity/digestforum');
        if ($userinfo) {
            $paths[] = new restore_path_element('digestforum_discussion', '/activity/digestforum/discussions/discussion');
            $paths[] = new restore_path_element('digestforum_post', '/activity/digestforum/discussions/discussion/posts/post');
            $paths[] = new restore_path_element('digestforum_rating', '/activity/digestforum/discussions/discussion/posts/post/ratings/rating');
            $paths[] = new restore_path_element('digestforum_subscription', '/activity/digestforum/subscriptions/subscription');
            $paths[] = new restore_path_element('digestforum_read', '/activity/digestforum/readposts/read');
            $paths[] = new restore_path_element('digestforum_track', '/activity/digestforum/trackedprefs/track');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_digestforum($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->assesstimestart = $this->apply_date_offset($data->assesstimestart);
        $data->assesstimefinish = $this->apply_date_offset($data->assesstimefinish);
        if ($data->scale < 0) { // scale found, get mapping
            $data->scale = -($this->get_mappingid('scale', abs($data->scale)));
        }

        $newitemid = $DB->insert_record('digestforum', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function process_digestforum_discussion($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->digestforum = $this->get_new_parentid('digestforum');
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timeend = $this->apply_date_offset($data->timeend);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->usermodified = $this->get_mappingid('user', $data->usermodified);

        $newitemid = $DB->insert_record('digestforum_discussions', $data);
        $this->set_mapping('digestforum_discussion', $oldid, $newitemid);
    }

    protected function process_digestforum_post($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('digestforum_discussion');
        $data->created = $this->apply_date_offset($data->created);
        $data->modified = $this->apply_date_offset($data->modified);
        $data->userid = $this->get_mappingid('user', $data->userid);
        // If post has parent, map it (it has been already restored)
        if (!empty($data->parent)) {
            $data->parent = $this->get_mappingid('digestforum_post', $data->parent);
        }

        $newitemid = $DB->insert_record('digestforum_posts', $data);
        $this->set_mapping('digestforum_post', $oldid, $newitemid, true);

        // If !post->parent, it's the 1st post. Set it in discussion
        if (empty($data->parent)) {
            $DB->set_field('digestforum_discussions', 'firstpost', $newitemid, array('id' => $data->discussion));
        }
    }

    protected function process_digestforum_rating($data) {
        global $DB;

        $data = (object)$data;

        // Cannot use ratings API, cause, it's missing the ability to specify times (modified/created)
        $data->contextid = $this->task->get_contextid();
        $data->itemid    = $this->get_new_parentid('digestforum_post');
        if ($data->scaleid < 0) { // scale found, get mapping
            $data->scaleid = -($this->get_mappingid('scale', abs($data->scaleid)));
        }
        $data->rating = $data->value;
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // We need to check that component and ratingarea are both set here.
        if (empty($data->component)) {
            $data->component = 'mod_digestforum';
        }
        if (empty($data->ratingarea)) {
            $data->ratingarea = 'post';
        }

        $newitemid = $DB->insert_record('rating', $data);
    }

    protected function process_digestforum_subscription($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->digestforum = $this->get_new_parentid('digestforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('digestforum_subscriptions', $data);
    }

    protected function process_digestforum_read($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->digestforumid = $this->get_new_parentid('digestforum');
        $data->discussionid = $this->get_mappingid('digestforum_discussion', $data->discussionid);
        $data->postid = $this->get_mappingid('digestforum_post', $data->postid);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('digestforum_read', $data);
    }

    protected function process_digestforum_track($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->digestforumid = $this->get_new_parentid('digestforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('digestforum_track_prefs', $data);
    }

    protected function after_execute() {
        global $DB;

        // Add digestforum related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_digestforum', 'intro', null);

        // If the digestforum is of type 'single' and no discussion has been ignited
        // (non-userinfo backup/restore) create the discussion here, using digestforum
        // information as base for the initial post.
        $digestforumid = $this->task->get_activityid();
        $digestforumrec = $DB->get_record('digestforum', array('id' => $digestforumid));
        if ($digestforumrec->type == 'single' && !$DB->record_exists('digestforum_discussions', array('digestforum' => $digestforumid))) {
            // Create single discussion/lead post from digestforum data
            $sd = new stdclass();
            $sd->course   = $digestforumrec->course;
            $sd->digestforum    = $digestforumrec->id;
            $sd->name     = $digestforumrec->name;
            $sd->assessed = $digestforumrec->assessed;
            $sd->message  = $digestforumrec->intro;
            $sd->messageformat = $digestforumrec->introformat;
            $sd->messagetrust  = true;
            $sd->mailnow  = false;
            $sdid = digestforum_add_discussion($sd, null, $sillybyrefvar, $this->task->get_userid());
            // Mark the post as mailed
            $DB->set_field ('digestforum_posts','mailed', '1', array('discussion' => $sdid));
            // Copy all the files from mod_foum/intro to mod_digestforum/post
            $fs = get_file_storage();
            $files = $fs->get_area_files($this->task->get_contextid(), 'mod_digestforum', 'intro');
            foreach ($files as $file) {
                $newfilerecord = new stdclass();
                $newfilerecord->filearea = 'post';
                $newfilerecord->itemid   = $DB->get_field('digestforum_discussions', 'firstpost', array('id' => $sdid));
                $fs->create_file_from_storedfile($newfilerecord, $file);
            }
        }

        // Add post related files, matching by itemname = 'digestforum_post'
        $this->add_related_files('mod_digestforum', 'post', 'digestforum_post');
        $this->add_related_files('mod_digestforum', 'attachment', 'digestforum_post');
    }
}
