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
 * @package    mod_digestdigestforum
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/digestdigestforum/backup/moodle2/restore_digestdigestforum_stepslib.php'); // Because it exists (must)

/**
 * digestdigestforum restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_digestdigestforum_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Choice only has one structure step
        $this->add_step(new restore_digestdigestforum_activity_structure_step('digestdigestforum_structure', 'digestdigestforum.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('digestdigestforum', array('intro'), 'digestdigestforum');
        $contents[] = new restore_decode_content('digestdigestforum_posts', array('message'), 'digestdigestforum_post');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        // List of digestdigestforums in course
        $rules[] = new restore_decode_rule('DDFORUMINDEX', '/mod/digestdigestforum/index.php?id=$1', 'course');
        // Forum by cm->id and digestdigestforum->id
        $rules[] = new restore_decode_rule('DDFORUMVIEWBYID', '/mod/digestdigestforum/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('DDFORUMVIEWBYF', '/mod/digestdigestforum/view.php?f=$1', 'digestdigestforum');
        // Link to digestdigestforum discussion
        $rules[] = new restore_decode_rule('DDFORUMDISCUSSIONVIEW', '/mod/digestdigestforum/discuss.php?d=$1', 'digestdigestforum_discussion');
        // Link to discussion with parent and with anchor posts
        $rules[] = new restore_decode_rule('DDFORUMDISCUSSIONVIEWPARENT', '/mod/digestdigestforum/discuss.php?d=$1&parent=$2',
                                           array('digestdigestforum_discussion', 'digestdigestforum_post'));
        $rules[] = new restore_decode_rule('DDFORUMDISCUSSIONVIEWINSIDE', '/mod/digestdigestforum/discuss.php?d=$1#$2',
                                           array('digestdigestforum_discussion', 'digestdigestforum_post'));

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * digestdigestforum logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('digestdigestforum', 'add', 'view.php?id={course_module}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'update', 'view.php?id={course_module}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'view', 'view.php?id={course_module}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'view digestdigestforum', 'view.php?id={course_module}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'mark read', 'view.php?f={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'start tracking', 'view.php?f={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'stop tracking', 'view.php?f={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'subscribe', 'view.php?f={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'unsubscribe', 'view.php?f={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'subscriber', 'subscribers.php?id={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'subscribers', 'subscribers.php?id={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'view subscribers', 'subscribers.php?id={digestdigestforum}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'add discussion', 'discuss.php?d={digestdigestforum_discussion}', '{digestdigestforum_discussion}');
        $rules[] = new restore_log_rule('digestdigestforum', 'view discussion', 'discuss.php?d={digestdigestforum_discussion}', '{digestdigestforum_discussion}');
        $rules[] = new restore_log_rule('digestdigestforum', 'move discussion', 'discuss.php?d={digestdigestforum_discussion}', '{digestdigestforum_discussion}');
        $rules[] = new restore_log_rule('digestdigestforum', 'delete discussi', 'view.php?id={course_module}', '{digestdigestforum}',
                                        null, 'delete discussion');
        $rules[] = new restore_log_rule('digestdigestforum', 'delete discussion', 'view.php?id={course_module}', '{digestdigestforum}');
        $rules[] = new restore_log_rule('digestdigestforum', 'add post', 'discuss.php?d={digestdigestforum_discussion}&parent={digestdigestforum_post}', '{digestdigestforum_post}');
        $rules[] = new restore_log_rule('digestdigestforum', 'update post', 'discuss.php?d={digestdigestforum_discussion}#p{digestdigestforum_post}&parent={digestdigestforum_post}', '{digestdigestforum_post}');
        $rules[] = new restore_log_rule('digestdigestforum', 'update post', 'discuss.php?d={digestdigestforum_discussion}&parent={digestdigestforum_post}', '{digestdigestforum_post}');
        $rules[] = new restore_log_rule('digestdigestforum', 'prune post', 'discuss.php?d={digestdigestforum_discussion}', '{digestdigestforum_post}');
        $rules[] = new restore_log_rule('digestdigestforum', 'delete post', 'discuss.php?d={digestdigestforum_discussion}', '[post]');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('digestdigestforum', 'view digestdigestforums', 'index.php?id={course}', null);
        $rules[] = new restore_log_rule('digestdigestforum', 'subscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('digestdigestforum', 'unsubscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('digestdigestforum', 'user report', 'user.php?course={course}&id={user}&mode=[mode]', '{user}');
        $rules[] = new restore_log_rule('digestdigestforum', 'search', 'search.php?id={course}&search=[searchenc]', '[search]');

        return $rules;
    }
}
