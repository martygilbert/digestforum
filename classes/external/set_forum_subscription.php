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

namespace mod_digestforum\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_digestforum\local\exporters\digestforum as digestforum_exporter;

/**
 * Web Service to control the state of a digestforum subscription
 *
 * @package   mod_digestforum
 * @category  external
 * @copyright 2025 Sara Arjona <sara@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_digestforum_subscription extends external_api {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum that the user wants to subscribe or unsubscribe from'),
                'targetstate' => new external_value(PARAM_BOOL, 'The target state'),
            ]
        );
    }

    /**
     * Set the digestforum subscription state.
     *
     * @param int $digestforumid The digestforum identifier.
     * @param bool $targetstate Whether to subscribe or unsubscribe the user to the digestforum.
     * @return  \stdClass
     */
    public static function execute(
        int $digestforumid,
        bool $targetstate,
    ): \stdClass {
        global $PAGE, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'digestforumid' => $digestforumid,
            'targetstate' => $targetstate,
        ]);

        $vaultfactory = \mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($params['digestforumid']);
        if (!$digestforum) {
            throw new \moodle_exception('invaliddigestforumid', 'mod_digestforum', '', $params['digestforumid']);
        }
        $coursemodule = $digestforum->get_course_module_record();
        $context = $digestforum->get_context();

        self::validate_context($context);

        $legacydatamapperfactory = \mod_digestforum\local\container::get_legacy_data_mapper_factory();
        $digestforumrecord = $legacydatamapperfactory->get_digestforum_data_mapper()->to_legacy_object($digestforum);
        if (
            !\mod_digestforum\subscriptions::is_subscribable($digestforumrecord)
            && !has_capability('mod/digestforum:managesubscriptions', $context)
        ) {
            // Nothing to do. We won't actually output any content here though.
            throw new \moodle_exception('cannotsubscribe', 'mod_digestforum');
        }

        $issubscribed = \mod_digestforum\subscriptions::is_subscribed(
            $USER->id,
            $digestforumrecord,
            null,
            $coursemodule
        );

        // If the current state doesn't equal the desired state then update the current
        // state to the desired state.
        if ($issubscribed != (bool) $params['targetstate']) {
            if ($params['targetstate']) {
                \mod_digestforum\subscriptions::subscribe_user($USER->id, $digestforumrecord, $context, true);
            } else {
                \mod_digestforum\subscriptions::unsubscribe_user($USER->id, $digestforumrecord, $context, true);
            }
        }

        /** @var \mod_digestforum\local\factories\exporter $exporterfactory */
        $exporterfactory = \mod_digestforum\local\container::get_exporter_factory();
        $exporter = $exporterfactory->get_digestforum_exporter(
            user: $USER,
            digestforum: $digestforum,
            currentgroup: null,
        );
        return $exporter->export($PAGE->get_renderer('mod_digestforum'));
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return digestforum_exporter::get_read_structure();
    }
}
