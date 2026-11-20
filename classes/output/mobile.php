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

namespace mod_digestforum\output;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/digestforum/externallib.php');

/**
 * Screens for the Moodle app.
 *
 * The data comes from the plugin's web services, so the app sees the same permissions as the web services enforce.
 * Posting is done from the app by calling the add_discussion and add_discussion_post web services.
 *
 * The app compiles the templates as Angular, so anything written by users (discussion names, post content, names)
 * is passed in otherdata and bound in the template, never written into the template itself.
 *
 * @package    mod_digestforum
 * @copyright  2026 Marty Gilbert <martygilbert@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /** @var int The number of discussions shown on each page of the discussion list. */
    const DISCUSSIONS_PER_PAGE = 25;

    /**
     * JavaScript run by the app for each screen.
     *
     * The app's text areas give plain text, but the web services expect HTML, so convert it before sending.
     */
    const JAVASCRIPT = <<<'JS'
this.textToHtml = function(text) {
    var escaped = (text || '').trim()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return escaped.split(/\n{2,}/).map(function(paragraph) {
        return '<p>' + paragraph.replace(/\n/g, '<br>') + '</p>';
    }).join('');
};
JS;

    /**
     * The discussion list for a digestforum, shown when the activity is opened in the app.
     *
     * @param array $args Arguments from the app: cmid, courseid, and optionally page.
     * @return array The template, JavaScript and data for the app.
     */
    public static function mobile_course_view($args) {
        global $DB, $OUTPUT;

        $args = (object) $args;
        $page = isset($args->page) ? max(0, (int) $args->page) : 0;

        $cm = get_coursemodule_from_id('digestforum', $args->cmid, 0, false, MUST_EXIST);
        $digestforum = $DB->get_record('digestforum', ['id' => $cm->instance], '*', MUST_EXIST);

        // Viewing the list counts as viewing the activity, for logs and completion.
        if ($page === 0) {
            \mod_digestforum_external::view_digestforum($digestforum->id);
        }

        $discussions = external_api::clean_returnvalue(
            \mod_digestforum_external::get_digestforum_discussions_returns(),
            \mod_digestforum_external::get_digestforum_discussions($digestforum->id, -1, $page, self::DISCUSSIONS_PER_PAGE + 1)
        )['discussions'];

        // One extra discussion was fetched to find out whether there is another page.
        $hasolder = count($discussions) > self::DISCUSSIONS_PER_PAGE;
        $discussions = array_slice($discussions, 0, self::DISCUSSIONS_PER_PAGE);

        $canadd = $page === 0 && external_api::clean_returnvalue(
            \mod_digestforum_external::can_add_discussion_returns(),
            \mod_digestforum_external::can_add_discussion($digestforum->id)
        )['status'];

        $data = [
            'cmid' => $cm->id,
            'courseid' => $cm->course,
            'digestforumid' => $digestforum->id,
            'canadd' => $canadd,
            'hasnewer' => $page > 0,
            'newerpage' => $page - 1,
            'hasolder' => $hasolder,
            'olderpage' => $page + 1,
        ];

        $discussiondata = array_map(function ($discussion) {
            $replies = $discussion['numreplies'] == 1 ? 'repliesone' : 'repliesmany';
            return [
                'id' => $discussion['discussion'],
                'name' => format_string($discussion['name']),
                'author' => $discussion['userfullname'],
                'timemodified' => userdate($discussion['timemodified'], get_string('strftimedatetimeshort', 'langconfig')),
                'replies' => get_string($replies, 'digestforum', $discussion['numreplies']),
                'numunread' => $discussion['numunread'],
                'pinned' => $discussion['pinned'],
                'locked' => $discussion['locked'],
            ];
        }, $discussions);

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_digestforum/mobile_discussion_list', $data),
                ],
            ],
            'javascript' => self::JAVASCRIPT,
            'otherdata' => [
                'intro' => $page === 0 ? format_module_intro('digestforum', $digestforum, $cm->id) : '',
                'discussions' => json_encode($discussiondata),
                'showaddform' => 0,
                'subject' => '',
                'message' => '',
            ],
        ];
    }

    /**
     * The posts in one discussion, with a form to reply to each post.
     *
     * @param array $args Arguments from the app: cmid, courseid and discussionid.
     * @return array The template, JavaScript and data for the app.
     */
    public static function mobile_discussion_view($args) {
        global $OUTPUT;

        $args = (object) $args;
        $cm = get_coursemodule_from_id('digestforum', $args->cmid, 0, false, MUST_EXIST);

        \mod_digestforum_external::view_digestforum_discussion($args->discussionid);

        $posts = external_api::clean_returnvalue(
            \mod_digestforum_external::get_discussion_posts_returns(),
            \mod_digestforum_external::get_discussion_posts($args->discussionid, 'created', 'ASC')
        )['posts'];

        $data = [
            'cmid' => $cm->id,
            'courseid' => $cm->course,
        ];

        $postdata = array_map(function ($post) {
            return [
                'id' => $post['id'],
                'subject' => $post['subject'],
                'replysubject' => $post['replysubject'],
                'message' => $post['message'],
                'author' => $post['author']['fullname'],
                'authorpicture' => $post['author']['urls']['profileimage'] ?? '',
                'timecreated' => userdate($post['timecreated'], get_string('strftimedatetimeshort', 'langconfig')),
                'unread' => !empty($post['unread']),
                'isprivatereply' => $post['isprivatereply'],
                'canreply' => $post['capabilities']['reply'],
            ];
        }, $posts);

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_digestforum/mobile_discussion', $data),
                ],
            ],
            'javascript' => self::JAVASCRIPT,
            'otherdata' => [
                'posts' => json_encode($postdata),
                'replyto' => 0,
                'subject' => '',
                'message' => '',
            ],
        ];
    }
}
