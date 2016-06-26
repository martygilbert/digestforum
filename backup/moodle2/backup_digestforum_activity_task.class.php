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
 * Defines backup_digestdigestforum_activity_task class
 *
 * @package   mod_digestdigestforum
 * @category  backup
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/digestdigestforum/backup/moodle2/backup_digestdigestforum_stepslib.php');
require_once($CFG->dirroot . '/mod/digestdigestforum/backup/moodle2/backup_digestdigestforum_settingslib.php');

/**
 * Provides the steps to perform one complete backup of the Forum instance
 */
class backup_digestdigestforum_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity
     */
    protected function define_my_settings() {
    }

    /**
     * Defines a backup step to store the instance data in the digestdigestforum.xml file
     */
    protected function define_my_steps() {
        $this->add_step(new backup_digestdigestforum_activity_structure_step('digestdigestforum structure', 'digestdigestforum.xml'));
    }

    /**
     * Encodes URLs to the index.php, view.php and discuss.php scripts
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot,"/");

        // Link to the list of digestdigestforums
        $search="/(".$base."\/mod\/digestdigestforum\/index.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMINDEX*$2@$', $content);

        // Link to digestdigestforum view by moduleid
        $search="/(".$base."\/mod\/digestdigestforum\/view.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMVIEWBYID*$2@$', $content);

        // Link to digestdigestforum view by digestdigestforumid
        $search="/(".$base."\/mod\/digestdigestforum\/view.php\?f\=)([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMVIEWBYF*$2@$', $content);

        // Link to digestdigestforum discussion with parent syntax
        $search = "/(".$base."\/mod\/digestdigestforum\/discuss.php\?d\=)([0-9]+)(?:\&amp;|\&)parent\=([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMDISCUSSIONVIEWPARENT*$2*$3@$', $content);

        // Link to digestdigestforum discussion with relative syntax
        $search="/(".$base."\/mod\/digestdigestforum\/discuss.php\?d\=)([0-9]+)\#([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMDISCUSSIONVIEWINSIDE*$2*$3@$', $content);

        // Link to digestdigestforum discussion by discussionid
        $search="/(".$base."\/mod\/digestdigestforum\/discuss.php\?d\=)([0-9]+)/";
        $content= preg_replace($search, '$@DDFORUMDISCUSSIONVIEW*$2@$', $content);

        return $content;
    }
}
