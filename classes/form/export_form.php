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
 * This file contains the form definition for discussion export.
 *
 * @package   mod_digestforum
 * @copyright 2019 Simey Lameze <simey@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\form;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

require_once($CFG->dirroot.'/mod/digestforum/lib.php');
require_once($CFG->libdir.'/formslib.php');

/**
 * Export discussion form.
 *
 * @package   mod_digestforum
 * @copyright 2019 Simey Lameze <simey@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL Juv3 or later
 */
class export_form extends \moodleform {

    /**
     * Define the form - called by parent constructor
     */
    public function definition() {
        $mform = $this->_form;
        $digestforum = $this->_customdata['digestforum'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', $digestforum->get_id());

        $options = [
            'ajax' => 'mod_digestforum/form-user-selector',
            'multiple' => true,
            'noselectionstring' => get_string('allusers', 'mod_digestforum'),
            'data-contextid' => $digestforum->get_context()->id,
            'courseid' => $digestforum->get_course_id(),
                'valuehtmlcallback' => function($value) {
                    global $OUTPUT;

                    $userfieldsapi = \core_user\fields::for_name();
                    $allusernames = $userfieldsapi->get_sql('', false, '', '', false)->selects;
                    $fields = 'id, ' . $allusernames;
                    $user = \core_user::get_user($value, $fields);
                    $useroptiondata = [
                        'fullname' => fullname($user),
                    ];
                    return $OUTPUT->render_from_template('mod_digestforum/form-user-selector-suggestion', $useroptiondata);
                }
        ];
        $mform->addElement('autocomplete', 'useridsselected', get_string('users'), [], $options);

        $vaultfactory = \mod_digestforum\local\container::get_vault_factory();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussions = array_map(function($discussion) {
            return $discussion->get_name();
        }, $discussionvault->get_all_discussions_in_digestforum($digestforum));
        $options = [
            'multiple' => true,
            'noselectionstring' => get_string('alldiscussions', 'mod_digestforum'),
        ];
        $mform->addElement('autocomplete', 'discussionids', get_string('discussions', 'mod_digestforum'), $discussions, $options);

        // Date fields.
        $mform->addElement('date_time_selector', 'from', get_string('postsfrom', 'mod_digestforum'),
                ['optional' => true]);
        $mform->addElement('date_time_selector', 'to', get_string('poststo', 'mod_digestforum'),
                ['optional' => true]);

        // Export formats.
        $formats = \core_plugin_manager::instance()->get_plugins_of_type('dataformat');
        $options = [];
        foreach ($formats as $format) {
            $options[$format->name] = $format->displayname;
        }
        $mform->addElement('select', 'format', 'Format', $options);

        $mform->addElement('header', 'optionsheader', get_string('exportoptions', 'mod_digestforum'));

        $mform->addElement('checkbox', 'striphtml', '', get_string('exportstriphtml', 'mod_digestforum'));
        $mform->addHelpButton('striphtml', 'exportstriphtml', 'mod_digestforum');

        $mform->addElement('checkbox', 'humandates', '', get_string('exporthumandates', 'mod_digestforum'));
        $mform->addHelpButton('humandates', 'exporthumandates', 'mod_digestforum');

        $this->add_action_buttons(true, get_string('export', 'mod_digestforum'));
    }
}
