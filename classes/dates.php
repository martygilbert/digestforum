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
 * Contains the class for fetching the important dates in mod_digestforum for a given module instance and a user.
 *
 * @package   mod_digestforum
 * @copyright 2021 Shamim Rezaie <shamim@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_digestforum;

use core\activity_dates;

/**
 * Class for fetching the important dates in mod_digestforum for a given module instance and a user.
 *
 * @copyright 2021 Shamim Rezaie <shamim@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates extends activity_dates {
    /**
     * Returns a list of important dates in mod_digestforum
     *
     * @return array
     */
    protected function get_dates(): array {
        $duedate = $this->cm->customdata['duedate'] ?? null;
        $dates = [];

        if ($duedate) {
            $dates[] = [
                'dataid' => 'duedate',
                'label' => get_string('activitydate:due', 'mod_digestforum'),
                'timestamp' => (int) $duedate,
            ];
        }

        return $dates;
    }
}
