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
 * Forum external functions and service definitions.
 *
 * @package    mod_digestforum
 * @copyright  2012 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$functions = array(

    'mod_digestforum_get_digestforums_by_courses' => array(
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_digestforums_by_courses',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum instances in a provided set of courses, if
            no courses are provided then all the digestforum instances the user has access to will be
            returned.',
        'type' => 'read',
        'capabilities' => 'mod/digestforum:viewdiscussion'
    ),

    'mod_digestforum_get_digestforum_discussions' => array(
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_digestforum_discussions',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum discussions contained within a given set of digestforums.',
        'type' => 'read',
        'capabilities' => 'mod/digestforum:viewdiscussion, mod/digestforum:viewqandawithoutposting'
    )
);
