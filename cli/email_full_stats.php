<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Email the digest open stats for all users.
 *
 * The output is one line per user and digestforum: Person - Forum - Sent - Read - %.
 *
 * @package    mod_digestforum
 * @copyright  2022 Marty Gilbert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once("$CFG->libdir/clilib.php");

// Now get cli options.
[$options, $unrecognized] = cli_get_params(['displayonly' => false,
    'emailonly' => false, 'both' => false, 'csv' => false, 'help' => false], ['h' => 'help']);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
        $help =
    "Email stats settings

Options:
--displayonly   Display stats only. Default
--emailonly     Email the stats only. Must supply username to email.
--both          Both display *and* email the stats. Takes precedent if multiple
				flags set. Must supply username to email.
--csv			Format as CSV. Tabular if not.
-h, --help      Print out this help

Example:
\$ sudo -u apache /usr/bin/php email_full_stats.php
\$ sudo -u apache /usr/bin/php email_full_stats.php --both=johndoe
\$ sudo -u apache /usr/bin/php email_full_stats.php --emailonly=johndoe

"; // Not localised yet.

    echo $help;
    die;
}

if (
    !$options['emailonly'] &&
    !$options['displayonly'] &&
    !$options['both']
) {
    $options['displayonly'] = true;
}


if ($options['emailonly'] && $options['displayonly']) {
    echo "Cannot have 'emailonly' and 'displayonly' set.\n";
    die;
}

$csv = false;
if ($options['csv']) {
    $csv = true;
}

if ($options['both'] || $options['displayonly'] && !$csv) {
    printf(
        "%-18s %-15s %12s %12s %13s\n",
        "Username",
        "Forum",
        "Num Opened",
        "Not Opened",
        "% Opened"
    );
}

$todaysdate = userdate(time(), '%Y-%m-%d');

$posthtml = '<style type="text/css">
table, th, td {
    border: 1px solid;
    padding: 10px;
    text-align: left;
    border-collapse: collapse;
}
.title {
    font-weight: bold;
    text-align: center;
}
</style>' .
"<h3>Read Stats for the Daily Digest Emails as of $todaysdate</h3>";
$header =
'<tr>
    <th>Date</th>
    <th style="text-align: right">Num Opened</th>
    <th style="text-align: right">Not Opened</th>
    <th style="text-align: right">Pct Opened</th>
</tr>';
$csvdata = 'User,Forum,Num Opened,Not Opened,Pct Opened' . "\n";

$digestforums = $DB->get_records('digestforum', null, '', 'id, name');


$count = 0;
foreach ($digestforums as $forum) {
    $sql =
        'SELECT mdluserid, digestforumid,
        COUNT(case when numviews > 0 then 1 end) AS NumOpened,
        COUNT(case when numviews = 0 then 1 end) AS NotOpened
            FROM {digestforum_tracker}
    	WHERE digestforumid = ? AND (digestdate like \'2023-02-%\' OR
		digestdate=\'2023-01-09\' OR
		digestdate like \'2023-01-1%\' OR
		digestdate like \'2023-01-3%\' OR
		digestdate like \'2023-01-2%\')
        GROUP BY mdluserid';

    /*
    WHERE digestforumid = ?
        GROUP BY mdluserid';
    */

    $opened = $DB->get_records_sql($sql, [$forum->id]);

    $name = preg_replace('/Daily Announcements for /', '', $forum->name);
    $name = preg_replace('/Daily Announcements Only for /', '', $name);

    $thistable =
    '<br><table>
<tr>
    <td colspan="4" class="title">' . $name . '</td>
</tr>
' . $header;
    foreach ($opened as $value) {
        $user = $DB->get_record('user', ['id' => $value->mdluserid]);
        if (!$user) {
            echo "Cannot find user with id" . $value->mdluserid . "\n";
        }

        if ($user->deleted || $user->suspended) {
            continue;
        }

        $pctopened = 100 * ($value->numopened) / ($value->numopened + $value->notopened);

        if ($options['both'] || $options['displayonly'] || $csv) {
            $csvdata .=
                substr($user->username, 0, 18) . ',' .
                $name . ',' .
                $value->numopened . ',' .
                $value->notopened . ',' .
                $pctopened . "\n";

            if (!$csv) {
                printf(
                    "%-18s %-15s %12s %12s %12.2f%%\n",
                    substr($user->username, 0, 18),
                    $name,
                    $value->numopened,
                    $value->notopened,
                    $pctopened
                );
            }
        }
        $thistable .=
        '<tr>
    <td>' . $user->username . '</td>
    <td style="text-align: right">' . $value->numopened . '</td>
    <td style="text-align: right">' . $value->notopened . '</td>
    <td style="text-align: right">' . sprintf("%10.2f%%", $pctopened) . '</td>
</tr>';
    }
    if ($opened) {
        $thistable .= "</table>\n";
        $posthtml .= $thistable;
    }
}

if ($options['displayonly'] && $csv) {
    echo $csvdata;
}

if ($options['both'] || $options['emailonly']) {
    $userto = $DB->get_record('user', ['username' => $options['emailonly']]);

    if (!$userto) {
        $userto = $DB->get_record('user', ['username' => $options['both']]);
        if (!$userto) {
            echo "Invalid user supplied\n";
            die;
        }
    }

    if (!$csv) {
        $mailresult = email_to_user(
            $userto,
            core_user::get_noreply_user(),
            "Stats for $todaysdate",
            format_string($posthtml),
            $posthtml,
        );
    } else {
        $mailresult = email_to_user(
            $userto,
            core_user::get_noreply_user(),
            "Stats for $todaysdate",
            format_string($csvdata),
            $csvdata,
        );
    }

    if (!$mailresult) {
        echo "Error sending email\n";
    }
}

/**
 * Get the abbreviated day name for a digest date.
 *
 * @param string $datestr The digest date, as yyyy-mm-dd
 * @return string The day, such as 'Mon'
 */
function get_day($datestr) {
    $info = mktime(
        12,
        0,
        0,
        substr($datestr, 5, 2),
        substr($datestr, 8, 2),
        substr($datestr, 0, 4)
    );
    return date("D", $info);
}
