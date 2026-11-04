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
 * This file defines an adhoc task to send notifications.
 *
 * @package    mod_digestforum
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\task;

defined('MOODLE_INTERNAL') || die();

use html_writer;
require_once($CFG->dirroot . '/mod/digestforum/lib.php');

/**
 * Adhoc task to send moodle digestforum digests for the specified user.
 *
 * @package    mod_digestforum
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_user_digests extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * @var \stdClass   A shortcut to $USER.
     */
    protected $recipient;

    /**
     * @var bool[]  Whether the user can view fullnames for each digestforum.
     */
    protected $viewfullnames = [];

    /**
     * @var bool[]  Whether the user can post in each digestforum.
     */
    protected $canpostto = [];

    /**
     * @var \stdClass[] Courses with posts them.
     */
    protected $courses = [];

    /**
     * @var \stdClass[] Forums with posts them.
     */
    protected $digestforums = [];

    /**
     * @var \stdClass[] Discussions with posts them.
     */
    protected $discussions = [];

    /**
     * @var \stdClass[] The posts to be sent.
     */
    protected $posts = [];

    /**
     * @var \stdClass[] The various authors.
     */
    protected $users = [];

    /**
     * @var \stdClass[] A list of any per-digestforum digest preference that this user holds.
     */
    protected $digestforumdigesttypes = [];

    /**
     * @var bool    Whether the user has requested HTML or not.
     */
    protected $allowhtml = true;

    /**
     * @var string  The subject of the message.
     */
    protected $postsubject = '';

    /**
     * @var string  The plaintext content of the whole message.
     */
    protected $notificationtext = '';

    /**
     * @var string  The HTML content of the whole message.
     */
    protected $notificationhtml = '';

    /**
     * @var string  The plaintext content for the current discussion being processed.
     */
    protected $discussiontext = '';

    /**
     * @var string  The HTML content for the current discussion being processed.
     */
    protected $discussionhtml = '';

    /**
     * @var int     The number of messages sent in this digest.
     */
    protected $sentcount = 0;

    /**
     * @var \renderer[][] A cache of the different types of renderer, stored both by target (HTML, or Text), and type.
     */
    protected $renderers = [
        'html' => [],
        'text' => [],
    ];

    /**
     * @var int[] A list of post IDs to be marked as read for this user.
     */
    protected $markpostsasread = [];

    /**
     * Send out messages.
     * @throws \moodle_exception
     */
    public function execute() {
        $starttime = time();

        $this->recipient = \core_user::get_user($this->get_userid());
        $this->log_start("Sending digestforum digests for {$this->recipient->username} ({$this->recipient->id})");

        if (empty($this->recipient->mailformat) || $this->recipient->mailformat != 1) {
            // This user does not want to receive HTML.
            $this->allowhtml = false;
        }

        // Fetch all of the data we need to mail these posts.
        $this->prepare_data($starttime);

        if (empty($this->posts) || empty($this->discussions) || empty($this->digestforums)) {
            $this->log_finish("No messages found to send.");
            return;
        }

        // Each digestforum gets its own digest email.
        $discussionsbyforum = [];
        foreach ($this->discussions as $discussion) {
            $discussionsbyforum[$discussion->digestforum][$discussion->id] = $discussion;
        }

        $digestcount = 0;
        foreach ($discussionsbyforum as $digestforumid => $discussions) {
            if ($this->send_digestforum_digest($this->digestforums[$digestforumid], $discussions)) {
                $digestcount++;
            }
        }

        // Empty the queue, even if a send failed. Retrying would re-send the digests that did succeed.
        $this->empty_queue($this->recipient->id, $starttime);

        // We have finished all digest emails, update $CFG->digestforum_mailtimelast.
        set_config('digestforum_mailtimelast', $starttime);

        $this->log_finish("Sent {$digestcount} digest(s).");
    }

    /**
     * Compose and send the digest email for a single digestforum.
     *
     * @param   \stdClass   $digestforum The digestforum being digested
     * @param   \stdClass[] $discussions The discussions in this digestforum with queued posts
     * @return  bool Whether a digest was sent
     */
    protected function send_digestforum_digest($digestforum, array $discussions): bool {
        global $CFG, $DB;

        // Reset the per-message state.
        $this->postsubject = '';
        $this->notificationtext = '';
        $this->notificationhtml = '';
        $this->sentcount = 0;
        $this->markpostsasread = [];

        // Date strings use the recipient's timezone, as the cron sets them up as the current user.
        $digestdate = userdate(time(), '%Y-%m-%d');

        $this->log_start("Composing digest for digestforum {$digestforum->id}", 1);

        // Add the message headers.
        $this->add_message_header($digestforum, $digestdate);

        foreach ($discussions as $discussion) {
            // Raise the time limit for each discussion.
            \core_php_time_limit::raise(120);

            // Grab the data pertaining to this discussion.
            $digestforum = $this->digestforums[$discussion->digestforum];
            $course = $this->courses[$digestforum->course];
            $cm = get_fast_modinfo($course)->instances['digestforum'][$digestforum->id];
            $modcontext = \context_module::instance($cm->id);
            $coursecontext = \context_course::instance($course->id);

            if (empty($this->posts[$discussion->id])) {
                // Somehow there are no posts.
                // This should not happen but better safe than sorry.
                continue;
            }

            if (!$course->visible and !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
                // The course is hidden and the user does not have access to it.
                // Permissions may have changed since it was queued.
                continue;
            }

            if (!digestforum_user_can_see_discussion($digestforum, $discussion, $modcontext, $this->recipient)) {
                // User cannot see this discussion.
                // Permissions may have changed since it was queued.
                continue;
            }

            if (!\mod_digestforum\subscriptions::is_subscribed($this->recipient->id, $digestforum, $discussion->id, $cm)) {
                // The user does not subscribe to this digestforum as a whole, or to this specific discussion.
                continue;
            }

            // Fetch additional values relating to this digestforum.
            if (!isset($this->canpostto[$discussion->id])) {
                $this->canpostto[$discussion->id] = digestforum_user_can_post(
                        $digestforum, $discussion, $this->recipient, $cm, $course, $modcontext);
            }

            if (!isset($this->viewfullnames[$digestforum->id])) {
                $this->viewfullnames[$digestforum->id] = has_capability('moodle/site:viewfullnames', $modcontext, $this->recipient->id);
            }

            // Set the discussion storage values.
            $discussionpostcount = 0;
            $this->discussiontext = '';
            $this->discussionhtml = '';

            // Add the header for this discussion.
            $this->add_discussion_header($discussion, $digestforum, $course);
            $this->log_start("Adding messages in discussion {$discussion->id} (digestforum {$digestforum->id})", 1);

            // Add all posts in this digestforum.
            foreach ($this->posts[$discussion->id] as $post) {
                $author = $this->get_post_author($post->userid, $course, $digestforum, $cm, $modcontext);
                if (empty($author)) {
                    // Unable to find the author. Skip to avoid errors.
                    continue;
                }

                if (!digestforum_user_can_see_post($digestforum, $discussion, $post, $this->recipient, $cm)) {
                    // User cannot see this post.
                    // Permissions may have changed since it was queued.
                    continue;
                }

                $this->add_post_body($author, $post, $discussion, $digestforum, $cm, $course);
                $discussionpostcount++;
            }

            // Add the digestforum footer.
            $this->add_discussion_footer($discussion, $digestforum, $course);

            // Add the data for this discussion to the notification body.
            if ($discussionpostcount) {
                $this->sentcount += $discussionpostcount;
                $this->notificationtext .= $this->discussiontext;
                $this->notificationhtml .= $this->discussionhtml;
                $this->log_finish("Added {$discussionpostcount} messages to discussion {$discussion->id}", 1);
            } else {
                $this->log_finish("No messages found in discussion {$discussion->id} - skipped.", 1);
            }
        }

        if (!$this->sentcount) {
            $this->log_finish("No messages found to send.", 1);
            return false;
        }

        // Record the digest in the tracker, and add the tracking image so that opens can be counted.
        $trackerid = $DB->insert_record('digestforum_tracker', (object) [
            'mdluserid' => $this->recipient->id,
            'digestforumid' => $digestforum->id,
            'digestdate' => $digestdate,
        ]);
        if ($this->allowhtml) {
            $this->notificationhtml .= html_writer::empty_tag('img', [
                'src' => (new \moodle_url('/mod/digestforum/img.php', ['id' => $trackerid]))->out(false),
                'height' => '1',
                'width' => '1',
                'alt' => '',
            ]);
            $this->notificationhtml = $this->wrap_html($this->notificationhtml);
        }

        $messageid = digestforum_get_email_message_id($digestforum->id, $this->recipient->id, $digestdate);
        if (!$this->send_mail($messageid)) {
            $DB->delete_records('digestforum_tracker', ['id' => $trackerid]);
            $this->log_finish("Issue sending digest for digestforum {$digestforum->id}. Not trying again.", 1);
            return false;
        }

        $this->log_finish("Digest sent with {$this->sentcount} messages.", 1);
        if (get_user_preferences('digestforum_markasreadonnotification', 1, $this->recipient->id) == 1) {
            digestforum_tp_mark_posts_read($this->recipient, $this->markpostsasread);
        }
        return true;
    }

    /**
     * Prepare the data for this run.
     *
     * Note: This will also remove posts from the queue.
     *
     * @param   int     $timenow
     */
    protected function prepare_data(int $timenow) {
        global $DB;

        $sql = "SELECT p.*, f.id AS digestforum, f.course
                  FROM {digestforum_queue} q
            INNER JOIN {digestforum_posts} p ON p.id = q.postid
            INNER JOIN {digestforum_discussions} d ON d.id = p.discussion
            INNER JOIN {digestforum} f ON f.id = d.digestforum
                 WHERE q.userid = :userid
                   AND q.timemodified < :timemodified
              ORDER BY d.id, q.timemodified ASC";

        $queueparams = [
                'userid' => $this->recipient->id,
                'timemodified' => $timenow,
            ];

        $posts = $DB->get_recordset_sql($sql, $queueparams);
        $discussionids = [];
        $digestforumids = [];
        $courseids = [];
        $userids = [];
        foreach ($posts as $post) {
            $discussionids[] = $post->discussion;
            $digestforumids[] = $post->digestforum;
            $courseids[] = $post->course;
            $userids[] = $post->userid;
            unset($post->digestforum);
            if (!isset($this->posts[$post->discussion])) {
                $this->posts[$post->discussion] = [];
            }
            $this->posts[$post->discussion][$post->id] = $post;
        }
        $posts->close();

        if (empty($discussionids)) {
            // All posts have been removed since the task was queued.
            $this->empty_queue($this->recipient->id, $timenow);
            return;
        }

        list($in, $params) = $DB->get_in_or_equal($discussionids);
        $this->discussions = $DB->get_records_select('digestforum_discussions', "id {$in}", $params);

        list($in, $params) = $DB->get_in_or_equal($digestforumids);
        $this->digestforums = $DB->get_records_select('digestforum', "id {$in}", $params);

        list($in, $params) = $DB->get_in_or_equal($courseids);
        $this->courses = $DB->get_records_select('course', "id $in", $params);

        list($in, $params) = $DB->get_in_or_equal($userids);
        $this->users = $DB->get_records_select('user', "id $in", $params);

        $this->fill_digest_cache();
    }

    /**
     * Empty the queue of posts for this user.
     *
     * @param int $userid user id which queue elements are going to be removed.
     * @param int $timemodified up time limit of the queue elements to be removed.
     */
    protected function empty_queue(int $userid, int $timemodified): void {
        global $DB;

        $DB->delete_records_select('digestforum_queue', "userid = :userid AND timemodified < :timemodified", [
                'userid' => $userid,
                'timemodified' => $timemodified,
            ]);
    }

    /**
     * Fill the cron digest cache.
     */
    protected function fill_digest_cache() {
        global $DB;

        $this->digestforumdigesttypes = $DB->get_records_menu('digestforum_digests', [
                'userid' => $this->recipient->id,
            ], '', 'digestforum, maildigest');
    }

    /**
     * Fetch and initialise the post author.
     *
     * @param   int         $userid The id of the user to fetch
     * @param   \stdClass   $course
     * @param   \stdClass   $digestforum
     * @param   \stdClass   $cm
     * @param   \context    $context
     * @return  \stdClass
     */
    protected function get_post_author($userid, $course, $digestforum, $cm, $context) {
        if (!isset($this->users[$userid])) {
            // This user no longer exists.
            return false;
        }

        $user = $this->users[$userid];

        if (!isset($user->groups)) {
            // Initialise the groups list.
            $user->groups = [];
        }

        if (!isset($user->groups[$digestforum->id])) {
            $user->groups[$digestforum->id] = groups_get_all_groups($course->id, $user->id, $cm->groupingid);
        }

        // Clone the user object to prevent leaks between messages.
        return (object) (array) $user;
    }

    /**
     * Add the header to this message.
     */
    protected function add_message_header($digestforum, $digestdate) {
        $site = get_site();

        $headerdata = (object) [
            'sitename' => format_string($site->fullname, true),
            'digestforumname' => format_string($digestforum->name, true),
            'date' => userdate(time(), '%a %b %e, %Y'),
            'openrate' => mod_digestforum_get_month_open_rate($this->recipient->id, $digestforum->id, $digestdate),
        ];

        // Set the subject of the message.
        $this->postsubject = get_string('digestmailsubject', 'digestforum', $headerdata);

        $this->notificationtext .= get_string('digestmailheader', 'digestforum', $headerdata) . "\n\n";
        if ($headerdata->openrate >= 0) {
            $this->notificationtext .= get_string('digestmailheaderstat', 'digestforum', $headerdata) . "\n\n";
        }

        if ($this->allowhtml) {
            $this->notificationhtml .= html_writer::tag('h3', get_string('digestmailheader', 'digestforum', $headerdata), [
                    'style' => 'font-size: 20px; line-height: 1.3; margin: 0 0 4px 0; color: #1d2125;',
                ]);
            if ($headerdata->openrate >= 0) {
                $this->notificationhtml .= html_writer::div(get_string('digestmailheaderstat', 'digestforum', $headerdata),
                        'openrate', ['style' => 'font-size: 13px; color: #6a737b;']);
            }
            $this->notificationhtml .= html_writer::div('', '', [
                    'style' => 'border-top: 3px solid #1d2125; margin: 12px 0 8px 0;',
                ]);
        }
    }

    /**
     * Wrap the HTML body in a centred, fixed-width container, with the base font and image sizing.
     *
     * Most email clients ignore stylesheets, so styling is inline. The only exception is the image rule,
     * which has to reach the images inside the post messages.
     *
     * @param   string  $html The composed body
     * @return  string
     */
    protected function wrap_html(string $html): string {
        $style = html_writer::tag('style',
                '.digestforum-digest img { max-width: 100% !important; height: auto !important; } ' .
                '.digestforum-digest table { max-width: 100% !important; }');

        return $style . html_writer::div($html, 'digestforum-digest', [
                'style' => 'max-width: 600px; margin: 0 auto; padding: 16px; ' .
                    'font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; ' .
                    'font-size: 15px; line-height: 1.5; color: #1d2125;',
            ]);
    }

    /**
     * Add the header for this discussion.
     *
     * @param   \stdClass   $discussion The discussion to add the footer for
     * @param   \stdClass   $digestforum The digestforum that the discussion belongs to
     * @param   \stdClass   $course The course that the digestforum belongs to
     */
    protected function add_discussion_header($discussion, $digestforum, $course) {
        // The email is for a single digestforum, so only the discussion needs naming.
        $discussionname = format_string($discussion->name, true);
        $discussionurl = new \moodle_url('/mod/digestforum/discuss.php', [
                'd' => $discussion->id,
            ]);

        $this->discussiontext .= "\n=====================================================================\n\n";
        $this->discussiontext .= " -> {$discussionname}\n";
        $this->discussiontext .= $discussionurl->out(false) . "\n";

        if ($this->allowhtml) {
            $this->discussionhtml .= html_writer::tag('h4', html_writer::link($discussionurl, $discussionname, [
                    'target' => '_blank',
                    'style' => 'color: #0f6cbf; text-decoration: none;',
                ]), [
                    'style' => 'font-size: 18px; line-height: 1.3; margin: 20px 0 10px 0;',
                ]);
            $this->discussionhtml .= '<div>';
        }
    }

    /**
     * Add the body of this post.
     *
     * @param   \stdClass   $author The author of the post
     * @param   \stdClass   $post The post being sent
     * @param   \stdClass   $discussion The discussion that the post is in
     * @param   \stdClass   $digestforum The digestforum that the discussion belongs to
     * @param   \cminfo     $cm The cminfo object for the digestforum
     * @param   \stdClass   $course The course that the digestforum belongs to
     */
    protected function add_post_body($author, $post, $discussion, $digestforum, $cm, $course) {
        global $CFG;

        $canreply = $this->canpostto[$discussion->id];

        $data = new \mod_digestforum\output\digestforum_post_email(
            $course,
            $cm,
            $digestforum,
            $discussion,
            $post,
            $author,
            $this->recipient,
            $canreply
        );

        // Override the viewfullnames value.
        $data->viewfullnames = $this->viewfullnames[$digestforum->id];

        // Determine the type of digest being sent.
        $maildigest = $this->get_maildigest($digestforum->id);

        $textrenderer = $this->get_renderer($maildigest);
        $this->discussiontext .= $textrenderer->render($data);
        $this->discussiontext .= "\n";
        if ($this->allowhtml) {
            $htmlrenderer = $this->get_renderer($maildigest, true);
            $this->discussionhtml .= $htmlrenderer->render($data);
            $this->log("Adding post {$post->id} in format {$maildigest} with HTML", 2);
        } else {
            $this->log("Adding post {$post->id} in format {$maildigest} without HTML", 2);
        }

        if ($maildigest == 1 && !$CFG->digestforum_usermarksread) {
            // Create an array of postid's for this user to mark as read.
            $this->markpostsasread[] = $post->id;
        }

    }

    /**
     * Add the footer for this discussion.
     *
     * @param   \stdClass   $discussion The discussion to add the footer for
     */
    protected function add_discussion_footer($discussion) {
        // Everyone is subscribed and receives the digest, so there are no subscription or preference links.
        if ($this->allowhtml) {
            $this->discussionhtml .= '</div>';
            $this->discussionhtml .= html_writer::div('', '', [
                    'style' => 'border-top: 1px solid #dee2e6; margin: 8px 0;',
                ]);
        }
    }

    /**
     * Get the digestforum digest type for the specified digestforum, failing back to
     * the default setting for the current user if not specified.
     *
     * @param   int     $digestforumid
     * @return  int
     */
    protected function get_maildigest($digestforumid) {
        // Everyone receives the complete digest, regardless of their preferences.
        return 1;
    }

    /**
     * Send the composed message to the user.
     *
     * @param   string  $messageid The Message-ID header for this digest
     * @return  bool
     */
    protected function send_mail(string $messageid) {
        // Headers to help prevent auto-responders.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = array(
            "Precedence: Bulk",
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',
            'Message-ID: ' . $messageid,
        );

        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'mod_digestforum';
        $eventdata->name = 'digests';
        $eventdata->userfrom = $userfrom;
        $eventdata->userto = $this->recipient;
        $eventdata->subject = $this->postsubject;
        $eventdata->fullmessage = $this->notificationtext;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = $this->notificationhtml;
        $eventdata->notification = 1;
        $eventdata->smallmessage = get_string('smallmessagedigest', 'digestforum', $this->sentcount);

        return message_send($eventdata);
    }

    /**
     * Helper to fetch the required renderer, instantiating as required.
     *
     * @param   int     $maildigest The type of mail digest being sent
     * @param   bool    $html Whether to fetch the HTML renderer
     * @return  \core_renderer
     */
    protected function get_renderer($maildigest, $html = false) {
        global $PAGE;

        $type = $maildigest == 2 ? 'emaildigestbasic' : 'emaildigestfull';
        $target = $html ? 'htmlemail' : 'textemail';

        if (!isset($this->renderers[$target][$type])) {
            $this->renderers[$target][$type] = $PAGE->get_renderer('mod_digestforum', $type, $target);
        }

        return $this->renderers[$target][$type];
    }
}
