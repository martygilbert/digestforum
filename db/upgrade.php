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
 * This file keeps track of upgrades to
 * the digestforum module
 *
 * Sometimes, changes between versions involve
 * alterations to database structures and other
 * major things that may break installations.
 *
 * The upgrade function in this file will attempt
 * to perform all the necessary actions to upgrade
 * your older installation to the current version.
 *
 * If there's something it cannot do itself, it
 * will tell you what you need to do.
 *
 * The commands in here will all be database-neutral,
 * using the methods of database_manager class
 *
 * Please do not forget to use upgrade_set_timeout()
 * before any action that may take longer time to finish.
 *
 * @package   mod_digestforum
 * @copyright 2003 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_digestforum_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Automatically generated Moodle v4.4.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.5.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.0.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.1.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2025110500) {
        // Define field showimmediately to be added to digestforum.
        $table = new xmldb_table('digestforum');
        $field = new xmldb_field('showimmediately', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lockdiscussionafter');

        // Conditionally launch add field showimmediately.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2025110500, 'digestforum');
    }

    if ($oldversion < 2026022300) {
        // Changing precision of field name on table digestforum to (1333).
        $table = new xmldb_table('digestforum');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null, 'type');

        // Launch change of precision for field name.
        $dbman->change_field_precision($table, $field);

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2026022300, 'digestforum');
    }

    // Automatically generated Moodle v5.2.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.3.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2026110400) {
        // Bring a digestforum upgraded from the 3.6 version up to the 5.3 schema. The mod_forum upgrade steps that
        // made these changes (3.7 - 4.x) are no longer in this file. Every change is conditional, so sites that
        // already have the 5.3 schema are unaffected.

        // Fields added to digestforum.
        $table = new xmldb_table('digestforum');
        $fields = [
            new xmldb_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'introformat'),
            new xmldb_field('cutoffdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duedate'),
            new xmldb_field('grade_digestforum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'scale'),
            new xmldb_field(
                'grade_digestforum_notify',
                XMLDB_TYPE_INTEGER,
                '4',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'grade_digestforum'
            ),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Discussion locking, and the usermodified foreign key.
        $table = new xmldb_table('digestforum_discussions');
        $field = new xmldb_field('timelocked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pinned');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('usermodified', XMLDB_INDEX_NOTUNIQUE, ['usermodified']);
        if (!$dbman->index_exists($table, $index)) {
            $key = new xmldb_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
            $dbman->add_key($table, $key);
        }

        // Private replies and word counts on posts.
        $table = new xmldb_table('digestforum_posts');
        $fields = [
            new xmldb_field('privatereplyto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'deleted'),
            new xmldb_field('wordcount', XMLDB_TYPE_INTEGER, '20', null, null, null, null, 'privatereplyto'),
            new xmldb_field('charcount', XMLDB_TYPE_INTEGER, '20', null, null, null, null, 'wordcount'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('privatereplyto', XMLDB_INDEX_NOTUNIQUE, ['privatereplyto']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Read tracking indexes: replace the old userid-first indexes.
        $table = new xmldb_table('digestforum_read');
        $oldindexes = [
            new xmldb_index('userid-digestforumid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'digestforumid']),
            new xmldb_index('userid-discussionid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'discussionid']),
        ];
        foreach ($oldindexes as $index) {
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        $newindexes = [
            new xmldb_index('digestforumid-userid', XMLDB_INDEX_NOTUNIQUE, ['digestforumid', 'userid']),
            new xmldb_index('discussionid-userid', XMLDB_INDEX_NOTUNIQUE, ['discussionid', 'userid']),
            new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']),
        ];
        foreach ($newindexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // Whole-forum grading.
        if (!$dbman->table_exists('digestforum_grades')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'digestforum_grades');
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2026110400, 'digestforum');
    }

    if ($oldversion < 2026110401) {
        // Digests are sent by email only. The defaults in db/messages.php only apply when the provider is first
        // registered, so lock every output for existing sites: email on, everything else off.
        $base = 'mod_digestforum_digests';
        foreach ($DB->get_fieldset_select('message_processors', 'name', '1 = 1') as $processorname) {
            set_config("{$processorname}_provider_{$base}_locked", 1, 'message');
        }
        set_config("message_provider_{$base}_enabled", 'email', 'message');

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2026110401, 'digestforum');
    }

    if ($oldversion < 2026110402) {
        // Individual post notifications are never sent, so lock every output off for them.
        $base = 'mod_digestforum_posts';
        foreach ($DB->get_fieldset_select('message_processors', 'name', '1 = 1') as $processorname) {
            set_config("{$processorname}_provider_{$base}_locked", 1, 'message');
        }
        set_config("message_provider_{$base}_enabled", '', 'message');

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2026110402, 'digestforum');
    }

    return true;
}
