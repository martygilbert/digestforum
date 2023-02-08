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

defined('MOODLE_INTERNAL') || die();

function xmldb_digestforum_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    if ($oldversion < 2016091200) {

        // Define field lockdiscussionafter to be added to digestforum.
        $table = new xmldb_table('digestforum');
        $field = new xmldb_field('lockdiscussionafter', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'displaywordcount');

        // Conditionally launch add field lockdiscussionafter.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2016091200, 'digestforum');
    }

    // Automatically generated Moodle v3.2.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v3.3.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2017092200) {

        // Remove duplicate entries from digestforum_subscriptions.
        // Find records with multiple userid/digestforum combinations and find the highest ID.
        // Later we will remove all those entries.
        $sql = "
            SELECT MIN(id) as minid, userid, digestforum
            FROM {digestforum_subscriptions}
            GROUP BY userid, digestforum
            HAVING COUNT(id) > 1";

        if ($duplicatedrows = $DB->get_recordset_sql($sql)) {
            foreach ($duplicatedrows as $row) {
                $DB->delete_records_select('digestforum_subscriptions',
                    'userid = :userid AND digestforum = :digestforum AND id <> :minid', (array)$row);
            }
        }
        $duplicatedrows->close();

        // Define key useriddigestforum (primary) to be added to digestforum_subscriptions.
        $table = new xmldb_table('digestforum_subscriptions');
        $key = new xmldb_key('useriddigestforum', XMLDB_KEY_UNIQUE, array('userid', 'digestforum'));

        // Launch add key useriddigestforum.
        $dbman->add_key($table, $key);

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2017092200, 'digestforum');
    }

    // Automatically generated Moodle v3.4.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2018032900) {

        // Define field deleted to be added to digestforum_posts.
        $table = new xmldb_table('digestforum_posts');
        $field = new xmldb_field('deleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'mailnow');

        // Conditionally launch add field deleted.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2018032900, 'digestforum');
    }

    // Automatically generated Moodle v3.5.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v3.6.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2022101101) {
        // Define field id to be added to changeme.
        $table = new xmldb_table('digestforum_tracker');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('mdluserid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        $table->add_field('digestforumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'mdluserid');
        $table->add_field('digestdate', XMLDB_TYPE_CHAR, '25', null, null, null, null, 'digestforumid');
        $table->add_field('numviews', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'digestdate');
        $table->add_field('firstviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'numviews');
        $table->add_field('lastviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'firstviewed');


        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('digestforumid', XMLDB_KEY_FOREIGN, ['digestforumid'], 'digestforum', ['id']);
        $table->add_key('mdluserid', XMLDB_KEY_FOREIGN, ['mdluserid'], 'user', ['id']);

        // Conditionally launch add field timeviewed.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Digestforum savepoint reached.
        upgrade_mod_savepoint(true, 2022101101, 'digestforum');
    }

    return true;
}
