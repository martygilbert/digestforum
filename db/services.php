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

$functions = [

    'mod_digestforum_get_digestforums_by_courses' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_digestforums_by_courses',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum instances in a provided set of courses, if
            no courses are provided then all the digestforum instances the user has access to will be
            returned.',
        'type' => 'read',
        'capabilities' => 'mod/digestforum:viewdiscussion',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_get_discussion_posts' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_discussion_posts',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum posts for a discussion.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/digestforum:viewdiscussion, mod/digestforum:viewqandawithoutposting',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_get_digestforum_discussions' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_digestforum_discussions',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum discussions optionally sorted and paginated.',
        'type' => 'read',
        'capabilities' => 'mod/digestforum:viewdiscussion, mod/digestforum:viewqandawithoutposting',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_view_digestforum' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'view_digestforum',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Trigger the course module viewed event and update the module completion status.',
        'type' => 'write',
        'capabilities' => 'mod/digestforum:viewdiscussion',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_view_digestforum_discussion' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'view_digestforum_discussion',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Trigger the digestforum discussion viewed event.',
        'type' => 'write',
        'capabilities' => 'mod/digestforum:viewdiscussion',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_add_discussion_post' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'add_discussion_post',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Create new posts into an existing discussion.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/digestforum:replypost',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_add_discussion' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'add_discussion',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Add a new discussion into an existing digestforum.',
        'type' => 'write',
        'capabilities' => 'mod/digestforum:startdiscussion',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_can_add_discussion' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'can_add_discussion',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Check if the current user can add discussions in the given digestforum (and optionally for the given group).',
        'type' => 'read',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_get_digestforum_access_information' => [
        'classname'     => 'mod_digestforum_external',
        'methodname'    => 'get_digestforum_access_information',
        'description'   => 'Return capabilities information for a given digestforum.',
        'type'          => 'read',
        'services'      => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_set_subscription_state' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'set_subscription_state',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Set the subscription state',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_set_lock_state' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'set_lock_state',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Set the lock state for the discussion',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_toggle_favourite_state' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'toggle_favourite_state',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Toggle the favourite state',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_set_pin_state' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'set_pin_state',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Set the pin state',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_delete_post' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'delete_post',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Deletes a post or a discussion completely when the post is the discussion topic.',
        'type' => 'write',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_get_discussion_posts_by_userid' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_discussion_posts_by_userid',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Returns a list of digestforum posts for a discussion for a user.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/digestforum:viewdiscussion, mod/digestforum:viewqandawithoutposting',
    ],
    'mod_digestforum_get_discussion_post' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'get_discussion_post',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Get a particular discussion post.',
        'type' => 'read',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_prepare_draft_area_for_post' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'prepare_draft_area_for_post',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Prepares a draft area for editing a post.',
        'type' => 'write',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_update_discussion_post' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'update_discussion_post',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Updates a post or a discussion topic post.',
        'type' => 'write',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_set_digestforum_subscription' => [
        'classname' => 'mod_digestforum\external\set_digestforum_subscription',
        'methodname' => 'execute',
        'description' => 'Subscribe or unsubscribe the user to a digestforum.',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_set_digestforum_tracking' => [
        'classname' => 'mod_digestforum\external\set_digestforum_tracking',
        'methodname' => 'execute',
        'description' => 'Track or not unread messages in a digestforum for the user.',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_digestforum_mark_posts_read' => [
        'classname' => 'mod_digestforum_external',
        'methodname' => 'mark_posts_read',
        'classpath' => 'mod/digestforum/externallib.php',
        'description' => 'Mark digestforum posts as read.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/digestforum:viewdiscussion',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    'mod_digestforum_set_read_state' => [
        'classname' => 'mod_digestforum\external\set_read_state',
        'methodname' => 'execute',
        'description' => 'Set the read or unread state for a digestforum post for the current user.',
        'type' => 'write',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
