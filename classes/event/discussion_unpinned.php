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
 * The mod_digestforum discussion unpinned event.
 *
 * @package    mod_digestforum
 * @copyright  2014 Charles Fulton <fultonc@lafayette.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\event;
defined('MOODLE_INTERNAL') || die();

/**
 * The mod_digestforum discussion unpinned event.
 *
 * @package    mod_digestforum
 * @copyright  2014 Charles Fulton <fultonc@lafayette.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discussion_unpinned extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'digestforum_discussions';
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user {$this->userid} has unpinned the discussion {$this->objectid} in the digestforum {$this->other['digestforumid']}";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdiscussionunpinned', 'mod_digestforum');
    }

    /**
     * Get URL related to the action
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/digestforum/discuss.php', array('d' => $this->objectid));
    }

    /**
     * Return the legacy event log data.
     *
     * @return array|null
     */
    protected function get_legacy_logdata() {
        // The legacy log table expects a relative path to /mod/digestforum/.
        $logurl = substr($this->get_url()->out_as_local_url(), strlen('/mod/digestforum/'));
        return array($this->courseid, 'digestforum', 'unpin discussion', $logurl, $this->objectid, $this->contextinstanceid);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['digestforumid'])) {
            throw new \coding_exception('digestforumid must be set in $other.');
        }
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context passed must be module context.');
        }
        if (!isset($this->objectid)) {
            throw new \coding_exception('objectid must be set to the discussionid.');
        }
    }

    /**
     * Forum discussion object id mappings.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return array('db' => 'digestforum_discussions', 'restore' => 'digestforum_discussion');
    }

    /**
     * Forum id mappings.
     *
     * @return array
     */
    public static function get_other_mapping() {
        $othermapped = array();
        $othermapped['digestforumid'] = array('db' => 'digestforum', 'restore' => 'digestforum');

        return $othermapped;
    }
}
