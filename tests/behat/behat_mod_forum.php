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
 * Steps definitions related with the digestforum activity.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2013 David Monllaó
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode as TableNode;
/**
 * Forum-related steps definitions.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2013 David Monllaó
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_digestforum extends behat_base {

    /**
     * Adds a topic to the digestforum specified by it's name. Useful for the Announcements and blog-style digestforums.
     *
     * @Given /^I add a new topic to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_new_topic_to_digestforum_with($digestforumname, TableNode $table) {
        $this->add_new_discussion($digestforumname, $table, get_string('addanewtopic', 'digestforum'));
    }

    /**
     * Adds a discussion to the digestforum specified by it's name with the provided table data (usually Subject and Message). The step begins from the digestforum's course page.
     *
     * @Given /^I add a new discussion to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_digestforum_discussion_to_digestforum_with($digestforumname, TableNode $table) {
        $this->add_new_discussion($digestforumname, $table, get_string('addanewdiscussion', 'digestforum'));
    }

    /**
     * Adds a reply to the specified post of the specified digestforum. The step begins from the digestforum's page or from the digestforum's course page.
     *
     * @Given /^I reply "(?P<post_subject_string>(?:[^"]|\\")*)" post from "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $postname The subject of the post
     * @param string $digestforumname The digestforum name
     * @param TableNode $table
     */
    public function i_reply_post_from_digestforum_with($postsubject, $digestforumname, TableNode $table) {

        // Navigate to digestforum.
        $this->execute('behat_general::click_link', $this->escape($digestforumname));
        $this->execute('behat_general::click_link', $this->escape($postsubject));
        $this->execute('behat_general::click_link', get_string('reply', 'digestforum'));

        // Fill form and post.
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $table);

        $this->execute('behat_forms::press_button', get_string('posttodigestforum', 'digestforum'));
        $this->execute('behat_general::i_wait_to_be_redirected');
    }

    /**
     * Returns the steps list to add a new discussion to a digestforum.
     *
     * Abstracts add a new topic and add a new discussion, as depending
     * on the digestforum type the button string changes.
     *
     * @param string $digestforumname
     * @param TableNode $table
     * @param string $buttonstr
     */
    protected function add_new_discussion($digestforumname, TableNode $table, $buttonstr) {

        // Navigate to digestforum.
        $this->execute('behat_general::click_link', $this->escape($digestforumname));
        $this->execute('behat_forms::press_button', $buttonstr);

        // Fill form and post.
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $table);
        $this->execute('behat_forms::press_button', get_string('posttodigestforum', 'digestforum'));
        $this->execute('behat_general::i_wait_to_be_redirected');
    }

}
