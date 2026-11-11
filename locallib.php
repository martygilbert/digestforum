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
 * Library of functions for digestforum outside of the core api
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Event types.
define('DFORUM_EVENT_TYPE_DUE', 'due');

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/digestforum/lib.php');
require_once($CFG->libdir . '/portfolio/caller.php');

/**
 * Portfolio caller, for exporting a digestforum post or discussion.
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum_portfolio_caller extends portfolio_module_caller_base {
    /** @var int The id of the post to export. */
    protected $postid;
    /** @var int The id of the discussion to export. */
    protected $discussionid;
    /** @var int The id of the attachment to export. */
    protected $attachment;

    /** @var stdClass The post being exported. */
    private $post;
    /** @var stdClass The digestforum the post or discussion is in. */
    private $digestforum;
    /** @var stdClass The discussion being exported. */
    private $discussion;
    /** @var stdClass[] The posts being exported. */
    private $posts;
    /** @var array The files of each post, keyed by post id. Multifiles isn't enough when exporting a full thread. */
    private $keyedfiles;

    /** @var context_module context instance. */
    private $modcontext;

    /**
     * The callback arguments the caller expects.
     *
     * @return array
     */
    public static function expected_callbackargs() {
        return [
            'postid'       => false,
            'discussionid' => false,
            'attachment'   => false,
        ];
    }
    /**
     * Create the caller, checking that a post or discussion was given.
     *
     * @param array $callbackargs
     */
    public function __construct($callbackargs) {
        parent::__construct($callbackargs);
        if (!$this->postid && !$this->discussionid) {
            throw new portfolio_caller_exception('mustprovidediscussionorpost', 'digestforum');
        }
    }
    /**
     * Load the post or discussion, and its files.
     */
    public function load_data() {
        global $DB;

        if ($this->postid) {
            if (!$this->post = $DB->get_record('digestforum_posts', ['id' => $this->postid])) {
                throw new portfolio_caller_exception('invalidpostid', 'digestforum');
            }
        }

        $dparams = [];
        if ($this->discussionid) {
            $dbparams = ['id' => $this->discussionid];
        } else if ($this->post) {
            $dbparams = ['id' => $this->post->discussion];
        } else {
            throw new portfolio_caller_exception('mustprovidediscussionorpost', 'digestforum');
        }

        if (!$this->discussion = $DB->get_record('digestforum_discussions', $dbparams)) {
            throw new portfolio_caller_exception('invaliddiscussionid', 'digestforum');
        }

        if (!$this->digestforum = $DB->get_record('digestforum', ['id' => $this->discussion->digestforum])) {
            throw new portfolio_caller_exception('invaliddigestforumid', 'digestforum');
        }

        if (!$this->cm = get_coursemodule_from_instance('digestforum', $this->digestforum->id)) {
            throw new portfolio_caller_exception('invalidcoursemodule');
        }

        $this->modcontext = context_module::instance($this->cm->id);
        $fs = get_file_storage();
        if ($this->post) {
            if ($this->attachment) {
                // Make sure the requested file belongs to this post.
                $file = $fs->get_file_by_id($this->attachment);
                if (
                    $file->get_contextid() != $this->modcontext->id
                    || $file->get_itemid() != $this->post->id
                ) {
                    throw new portfolio_caller_exception('filenotfound');
                }
                $this->set_file_and_format_data($this->attachment);
            } else {
                $attach = $fs->get_area_files(
                    $this->modcontext->id,
                    'mod_digestforum',
                    'attachment',
                    $this->post->id,
                    'timemodified',
                    false,
                );
                $embed  = $fs->get_area_files(
                    $this->modcontext->id,
                    'mod_digestforum',
                    'post',
                    $this->post->id,
                    'timemodified',
                    false,
                );
                $files = array_merge($attach, $embed);
                $this->set_file_and_format_data($files);
            }
            if (!empty($this->multifiles)) {
                $this->keyedfiles[$this->post->id] = $this->multifiles;
            } else if (!empty($this->singlefile)) {
                $this->keyedfiles[$this->post->id] = [$this->singlefile];
            }
        } else {
            // Whole thread.
            $fs = get_file_storage();
            $this->posts = digestforum_get_all_discussion_posts($this->discussion->id, 'p.created ASC');
            $this->multifiles = [];
            foreach ($this->posts as $post) {
                $attach = $fs->get_area_files(
                    $this->modcontext->id,
                    'mod_digestforum',
                    'attachment',
                    $post->id,
                    'timemodified',
                    false,
                );
                $embed  = $fs->get_area_files($this->modcontext->id, 'mod_digestforum', 'post', $post->id, 'timemodified', false);
                $files = array_merge($attach, $embed);
                if ($files) {
                    $this->keyedfiles[$post->id] = $files;
                } else {
                    continue;
                }
                $this->multifiles = array_merge($this->multifiles, array_values($this->keyedfiles[$post->id]));
            }
        }
        if (empty($this->multifiles) && !empty($this->singlefile)) {
            $this->multifiles = [$this->singlefile]; // Copy_files workaround.
        }
        // Depending on whether there are files or not, we might have to change richhtml/plainhtml.
        if (empty($this->attachment)) {
            if (!empty($this->multifiles)) {
                $this->add_format(PORTFOLIO_FORMAT_RICHHTML);
            } else {
                $this->add_format(PORTFOLIO_FORMAT_PLAINHTML);
            }
        }
    }

    /**
     * The page to return to after exporting.
     *
     * @return string
     */
    public function get_return_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/digestforum/discuss.php?d=' . $this->discussion->id;
    }
    /**
     * The navigation to show while exporting.
     *
     * @return array
     */
    public function get_navigation() {
        global $CFG;

        $navlinks = [];
        $navlinks[] = [
            'name' => format_string($this->discussion->name),
            'link' => $CFG->wwwroot . '/mod/digestforum/discuss.php?d=' . $this->discussion->id,
            'type' => 'title',
        ];
        return [$navlinks, $this->cm];
    }
    /**
     * either a whole discussion
     * a single post, with or without attachment
     * or just an attachment with no post
     *
     * @uses PORTFOLIO_FORMAT_RICH
     * @return mixed
     */
    public function prepare_package() {
        global $CFG;

        // Set up the leap2a writer if we need it.
        $writingleap = false;
        if ($this->exporter->get('formatclass') == PORTFOLIO_FORMAT_LEAP2A) {
            $leapwriter = $this->exporter->get('format')->leap2a_writer();
            $writingleap = true;
        }
        if ($this->attachment) {
            // Simplest case first - single file attachment.
            $this->copy_files([$this->singlefile], $this->attachment);
            if ($writingleap) {
                // If we're writing leap, make the manifest to go along with the file.
                $entry = new portfolio_format_leap2a_file($this->singlefile->get_filename(), $this->singlefile);
                $leapwriter->add_entry($entry);
                return $this->exporter->write_new_file(
                    $leapwriter->to_xml(),
                    $this->exporter->get('format')->manifest_name(),
                    true,
                );
            }
        } else if (empty($this->post)) {
            // Exporting whole discussion.
            $content = ''; // If we're just writing HTML, start a string to add each post to.
            $ids = []; // If we're writing leap2a, keep track of all entryids so we can add a selection element.
            foreach ($this->posts as $post) {
                $posthtml = $this->prepare_post($post);
                if ($writingleap) {
                    $ids[] = $this->prepare_post_leap2a($leapwriter, $post, $posthtml);
                } else {
                    $content .= $posthtml . '<br /><br />';
                }
            }
            $this->copy_files($this->multifiles);
            $name = 'discussion.html';
            $manifest = ($this->exporter->get('format') instanceof PORTFOLIO_FORMAT_RICH);
            if ($writingleap) {
                // Add on an extra 'selection' entry.
                $selection = new portfolio_format_leap2a_entry(
                    'digestforumdiscussion' . $this->discussionid,
                    get_string('discussion', 'digestforum') . ': ' . $this->discussion->name,
                    'selection'
                );
                $leapwriter->add_entry($selection);
                $leapwriter->make_selection($selection, $ids, 'Grouping');
                $content = $leapwriter->to_xml();
                $name = $this->get('exporter')->get('format')->manifest_name();
            }
            $this->get('exporter')->write_new_file($content, $name, $manifest);
        } else {
            // Exporting a single post.
            $posthtml = $this->prepare_post($this->post);

            $content = $posthtml;
            $name = 'post.html';
            $manifest = ($this->exporter->get('format') instanceof PORTFOLIO_FORMAT_RICH);

            if ($writingleap) {
                $this->prepare_post_leap2a($leapwriter, $this->post, $posthtml);
                $content = $leapwriter->to_xml();
                $name = $this->exporter->get('format')->manifest_name();
            }
            $this->copy_files($this->multifiles);
            $this->get('exporter')->write_new_file($content, $name, $manifest);
        }
    }

    /**
     * helper function to add a leap2a entry element
     * that corresponds to a single digestforum post,
     * including any attachments
     *
     * the entry/ies are added directly to the leapwriter, which is passed by ref
     *
     * @param portfolio_format_leap2a_writer $leapwriter writer object to add entries to
     * @param object $post                               the stdclass object representing the database record
     * @param string $posthtml                           the content of the post (prepared by {@see prepare_post})
     *
     * @return int id of new entry
     */
    private function prepare_post_leap2a(portfolio_format_leap2a_writer $leapwriter, $post, $posthtml) {
        $entry = new portfolio_format_leap2a_entry('digestforumpost' . $post->id, $post->subject, 'resource', $posthtml);
        $entry->published = $post->created;
        $entry->updated = $post->modified;
        $entry->author = $post->author;
        if (
            is_array($this->keyedfiles) && array_key_exists(
                $post->id,
                $this->keyedfiles,
            ) && is_array($this->keyedfiles[$post->id])
        ) {
            $leapwriter->link_files($entry, $this->keyedfiles[$post->id], 'digestforumpost' . $post->id . 'attachment');
        }
        $entry->add_category('web', 'resource_type');
        $leapwriter->add_entry($entry);
        return $entry->id;
    }

    /**
     * Copy files into the export.
     *
     * @param array $files
     * @param mixed $justone false, or the id of a single file to copy
     * @return bool|void
     */
    private function copy_files($files, $justone = false) {
        if (empty($files)) {
            return;
        }
        foreach ($files as $f) {
            if ($justone && $f->get_id() != $justone) {
                continue;
            }
            $this->get('exporter')->copy_existing_file($f);
            if ($justone && $f->get_id() == $justone) {
                return true; // All we need to do.
            }
        }
    }
    /**
     * this is a very cut down version of what is in digestforum_make_mail_post
     *
     * @param stdClass $post
     * @param array|null $fileoutputextras Extra options for the file output
     * @return string
     */
    private function prepare_post($post, $fileoutputextras = null) {
        global $DB;
        static $users;
        if (empty($users)) {
            $users = [$this->user->id => $this->user];
        }
        if (!array_key_exists($post->userid, $users)) {
            $users[$post->userid] = $DB->get_record('user', ['id' => $post->userid]);
        }
        // Add the user object on to the post so we can pass it to the leap writer if necessary.
        $post->author = $users[$post->userid];
        $viewfullnames = true;
        // Format the post body.
        $options = portfolio_format_text_options();
        $options->context = $this->modcontext;
        $format = $this->get('exporter')->get('format');
        $formattedtext = format_text($post->message, $post->messageformat, $options);
        $formattedtext = portfolio_rewrite_pluginfile_urls(
            $formattedtext,
            $this->modcontext->id,
            'mod_digestforum',
            'post',
            $post->id,
            $format,
        );

        $output = '<table border="0" cellpadding="3" cellspacing="0" class="digestforumpost">';

        $output .= '<tr class="header"><td>'; // Can't print picture.
        $output .= '</td>';

        if ($post->parent) {
            $output .= '<td class="topic">';
        } else {
            $output .= '<td class="topic starter">';
        }
        $output .= '<div class="subject">' . format_string($post->subject) . '</div>';

        $fullname = fullname($users[$post->userid], $viewfullnames);
        $by = new stdClass();
        $by->name = $fullname;
        $by->date = userdate($post->modified, '', core_date::get_user_timezone($this->user));
        $output .= '<div class="author">' . get_string('bynameondate', 'digestforum', $by) . '</div>';

        $output .= '</td></tr>';

        $output .= '<tr><td class="left side" valign="top">';

        $output .= '</td><td class="content">';

        $output .= $formattedtext;

        if (
            is_array($this->keyedfiles) && array_key_exists(
                $post->id,
                $this->keyedfiles,
            ) && is_array($this->keyedfiles[$post->id]) && count($this->keyedfiles[$post->id]) > 0
        ) {
            $output .= '<div class="attachments">';
            $output .= '<br /><b>' .  get_string('attachments', 'digestforum') . '</b>:<br /><br />';
            foreach ($this->keyedfiles[$post->id] as $file) {
                $output .= $format->file_output($file)  . '<br/ >';
            }
            $output .= "</div>";
        }

        $output .= '</td></tr></table>' . "\n\n";

        return $output;
    }
    /**
     * A hash of the content being exported, to check it has not changed.
     *
     * @return string
     */
    public function get_sha1() {
        $filesha = '';
        try {
            $filesha = $this->get_sha1_file();
        } catch (portfolio_caller_exception $e) {
            // There are no files, so there is no file sha1.
            $filesha = '';
        }

        if ($this->post) {
            return sha1($filesha . ',' . $this->post->subject . ',' . $this->post->message);
        } else {
            $sha1s = [$filesha];
            foreach ($this->posts as $post) {
                $sha1s[] = sha1($post->subject . ',' . $post->message);
            }
            return sha1(implode(',', $sha1s));
        }
    }

    #[\Override]
    public function expected_time() {
        $filetime = $this->expected_time_file();
        if ($this->posts) {
            $posttime = portfolio_expected_time_db(count($this->posts));
            if ($filetime < $posttime) {
                return $posttime;
            }
        }
        return $filetime;
    }
    /**
     * Check that the current user can export this post or discussion.
     *
     * @return bool
     */
    public function check_permissions() {
        $context = context_module::instance($this->cm->id);
        if ($this->post) {
            return (has_capability('mod/digestforum:exportpost', $context)
                || ($this->post->userid == $this->user->id
                    && has_capability('mod/digestforum:exportownpost', $context)));
        }
        return has_capability('mod/digestforum:exportdiscussion', $context);
    }
    /**
     * The name of the caller, shown to the user.
     *
     * @return string
     */
    public static function display_name() {
        return get_string('modulename', 'digestforum');
    }

    #[\Override]
    public static function base_supported_formats() {
        return [PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_RICHHTML, PORTFOLIO_FORMAT_PLAINHTML, PORTFOLIO_FORMAT_LEAP2A];
    }
}


/**
 * Class representing the virtual node with all itemids in the file browser
 *
 * @category  files
 * @copyright 2012 David Mudrak <david@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum_file_info_container extends file_info { // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
    /** @var file_browser */
    protected $browser;
    /** @var stdClass */
    protected $course;
    /** @var stdClass */
    protected $cm;
    /** @var string */
    protected $component;
    /** @var stdClass */
    protected $context;
    /** @var array */
    protected $areas;
    /** @var string */
    protected $filearea;

    /**
     * Constructor (in case you did not realize it ;-)
     *
     * @param file_browser $browser
     * @param stdClass $course
     * @param stdClass $cm
     * @param stdClass $context
     * @param array $areas
     * @param string $filearea
     */
    public function __construct($browser, $course, $cm, $context, $areas, $filearea) {
        parent::__construct($browser, $context);
        $this->browser = $browser;
        $this->course = $course;
        $this->cm = $cm;
        $this->component = 'mod_digestforum';
        $this->context = $context;
        $this->areas = $areas;
        $this->filearea = $filearea;
    }

    /**
     * The parameters of this file browser node.
     *
     * @return array with keys contextid, filearea, itemid, filepath and filename
     */
    public function get_params() {
        return [
            'contextid' => $this->context->id,
            'component' => $this->component,
            'filearea' => $this->filearea,
            'itemid' => null,
            'filepath' => null,
            'filename' => null,
        ];
    }

    /**
     * Can new files or directories be added via the file browser
     *
     * @return bool
     */
    public function is_writable() {
        return false;
    }

    /**
     * Should this node be considered as a folder in the file browser
     *
     * @return bool
     */
    public function is_directory() {
        return true;
    }

    /**
     * Returns localised visible name of this node
     *
     * @return string
     */
    public function get_visible_name() {
        return $this->areas[$this->filearea];
    }

    /**
     * Returns list of children nodes
     *
     * @return array of file_info instances
     */
    public function get_children() {
        return $this->get_filtered_children('*', false, true);
    }
    /**
     * Help function to return files matching extensions or their count
     *
     * @param string|array $extensions either '*' or array of lowercase extensions, i.e. array('.gif','.jpg')
     * @param bool|int $countonly if false returns the children, if an int returns just the
     *    count of children but stops counting when $countonly number of children is reached
     * @param bool $returnemptyfolders if true returns items that don't have matching files inside
     * @return array|int array of file_info instances or the count
     */
    private function get_filtered_children($extensions = '*', $countonly = false, $returnemptyfolders = false) {
        global $DB;
        $params = ['contextid' => $this->context->id,
            'component' => $this->component,
            'filearea' => $this->filearea];
        $sql = 'SELECT DISTINCT itemid
                    FROM {files}
                    WHERE contextid = :contextid
                    AND component = :component
                    AND filearea = :filearea';
        if (!$returnemptyfolders) {
            $sql .= ' AND filename <> :emptyfilename';
            $params['emptyfilename'] = '.';
        }
        [$sql2, $params2] = $this->build_search_files_sql($extensions);
        $sql .= ' ' . $sql2;
        $params = array_merge($params, $params2);
        if ($countonly !== false) {
            $sql .= ' ORDER BY itemid DESC';
        }

        $rs = $DB->get_recordset_sql($sql, $params);
        $children = [];
        foreach ($rs as $record) {
            if (
                ($child = $this->browser->get_file_info($this->context, 'mod_digestforum', $this->filearea, $record->itemid))
                    && ($returnemptyfolders || $child->count_non_empty_children($extensions))
            ) {
                $children[] = $child;
            }
            if ($countonly !== false && count($children) >= $countonly) {
                break;
            }
        }
        $rs->close();
        if ($countonly !== false) {
            return count($children);
        }
        return $children;
    }

    /**
     * Returns list of children which are either files matching the specified extensions
     * or folders that contain at least one such file.
     *
     * @param string|array $extensions either '*' or array of lowercase extensions, i.e. array('.gif','.jpg')
     * @return array of file_info instances
     */
    public function get_non_empty_children($extensions = '*') {
        return $this->get_filtered_children($extensions, false);
    }

    /**
     * Returns the number of children which are either files matching the specified extensions
     * or folders containing at least one such file.
     *
     * @param string|array $extensions for example '*' or array('.gif','.jpg')
     * @param int $limit stop counting after at least $limit non-empty children are found
     * @return int
     */
    public function count_non_empty_children($extensions = '*', $limit = 1) {
        return $this->get_filtered_children($extensions, $limit);
    }

    /**
     * Returns parent file_info instance
     *
     * @return file_info or null for root
     */
    public function get_parent() {
        return $this->browser->get_file_info($this->context);
    }
}

/**
 * Returns digestforum posts tagged with a specified tag.
 *
 * This is a callback used by the tag area mod_digestforum/digestforum_posts to search for digestforum posts
 * tagged with a specific tag.
 *
 * @param core_tag_tag $tag
 * @param bool $exclusivemode if set to true it means that no other entities tagged with this tag
 *             are displayed on the page and the per-page limit may be bigger
 * @param int $fromctx context id where the link was displayed, may be used by callbacks
 *            to display items in the same context first
 * @param int $ctx context id where to search for records
 * @param bool $rec search in subcontexts as well
 * @param int $page 0-based number of page being displayed
 * @return \core_tag\output\tagindex
 */
function mod_digestforum_get_tagged_posts($tag, $exclusivemode = false, $fromctx = 0, $ctx = 0, $rec = 1, $page = 0) {
    global $OUTPUT;
    $perpage = $exclusivemode ? 20 : 5;

    // Build the SQL query.
    $ctxselect = context_helper::get_preload_record_columns_sql('ctx');
    $query = "SELECT fp.id, fp.subject, fd.digestforum, fp.discussion, f.type, fd.timestart, fd.timeend, fd.groupid, fd.firstpost,
                    fp.parent, fp.userid,
                    cm.id AS cmid, c.id AS courseid, c.shortname, c.fullname, $ctxselect
                FROM {digestforum_posts} fp
                JOIN {digestforum_discussions} fd ON fp.discussion = fd.id
                JOIN {digestforum} f ON f.id = fd.digestforum
                JOIN {modules} m ON m.name='digestforum'
                JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = f.id
                JOIN {tag_instance} tt ON fp.id = tt.itemid
                JOIN {course} c ON cm.course = c.id
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :coursemodulecontextlevel
               WHERE tt.itemtype = :itemtype AND tt.tagid = :tagid AND tt.component = :component
                 AND cm.deletioninprogress = 0
                 AND fp.id %ITEMFILTER% AND c.id %COURSEFILTER%";

    $params = ['itemtype' => 'digestforum_posts', 'tagid' => $tag->id, 'component' => 'mod_digestforum',
                    'coursemodulecontextlevel' => CONTEXT_MODULE];

    if ($ctx) {
        $context = $ctx ? context::instance_by_id($ctx) : context_system::instance();
        $query .= $rec ? ' AND (ctx.id = :contextid OR ctx.path LIKE :path)' : ' AND ctx.id = :contextid';
        $params['contextid'] = $context->id;
        $params['path'] = $context->path . '/%';
    }

    $query .= " ORDER BY ";
    if ($fromctx) {
        // In order-clause specify that modules from inside "fromctx" context should be returned first.
        $fromcontext = context::instance_by_id($fromctx);
        $query .= ' (CASE WHEN ctx.id = :fromcontextid OR ctx.path LIKE :frompath THEN 0 ELSE 1 END),';
        $params['fromcontextid'] = $fromcontext->id;
        $params['frompath'] = $fromcontext->path . '/%';
    }
    $query .= ' c.sortorder, cm.id, fp.id';

    $totalpages = $page + 1;

    // Use core_tag_index_builder to build and filter the list of items.
    $builder = new core_tag_index_builder('mod_digestforum', 'digestforum_posts', $query, $params, $page * $perpage, $perpage + 1);
    while ($item = $builder->has_item_that_needs_access_check()) {
        context_helper::preload_from_record($item);
        $courseid = $item->courseid;
        if (!$builder->can_access_course($courseid)) {
            $builder->set_accessible($item, false);
            continue;
        }
        $modinfo = get_fast_modinfo($builder->get_course($courseid));
        // Set accessibility of this item and all other items in the same course.
        $builder->walk(function ($taggeditem) use ($courseid, $modinfo, $builder, $item) {
            // Checking permission for Q&A digestforums performs additional DB queries, do not do them in bulk.
            if ($taggeditem->courseid == $courseid && ($taggeditem->type != 'qanda' || $taggeditem->id == $item->id)) {
                $cm = $modinfo->get_cm($taggeditem->cmid);
                $digestforum = (object)['id'     => $taggeditem->digestforum,
                                  'course' => $taggeditem->courseid,
                                  'type'   => $taggeditem->type,
                ];
                $discussion = (object)['id'        => $taggeditem->discussion,
                                       'timestart' => $taggeditem->timestart,
                                       'timeend'   => $taggeditem->timeend,
                                       'groupid'   => $taggeditem->groupid,
                                       'firstpost' => $taggeditem->firstpost,
                ];
                $post = (object)['id' => $taggeditem->id,
                                       'parent' => $taggeditem->parent,
                                       'userid'   => $taggeditem->userid,
                                       'groupid'   => $taggeditem->groupid,
                ];

                $accessible = digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm);
                $builder->set_accessible($taggeditem, $accessible);
            }
        });
    }

    $items = $builder->get_items();
    if (count($items) > $perpage) {
        $totalpages = $page + 2; // We don't need exact page count, just indicate that the next page exists.
        array_pop($items);
    }

    // Build the display contents.
    if ($items) {
        $tagfeed = new core_tag\output\tagfeed();
        foreach ($items as $item) {
            context_helper::preload_from_record($item);
            $modinfo = get_fast_modinfo($item->courseid);
            $cm = $modinfo->get_cm($item->cmid);
            $pageurl = new moodle_url('/mod/digestforum/discuss.php', ['d' => $item->discussion], 'p' . $item->id);
            $pagename = format_string($item->subject, true, ['context' => context_module::instance($item->cmid)]);
            $pagename = html_writer::link($pageurl, $pagename);
            $courseurl = course_get_url($item->courseid, $cm->sectionnum);
            $cmname = html_writer::link($cm->url, $cm->get_formatted_name());
            $coursename = format_string($item->fullname, true, ['context' => context_course::instance($item->courseid)]);
            $coursename = html_writer::link($courseurl, $coursename);
            $icon = html_writer::link($pageurl, html_writer::empty_tag('img', ['src' => $cm->get_icon_url()]));
            $tagfeed->add($icon, $pagename, $cmname . '<br>' . $coursename);
        }

        $content = $OUTPUT->render_from_template(
            'core_tag/tagfeed',
            $tagfeed->export_for_template($OUTPUT)
        );

        return new core_tag\output\tagindex(
            $tag,
            'mod_digestforum',
            'digestforum_posts',
            $content,
            $exclusivemode,
            $fromctx,
            $ctx,
            $rec,
            $page,
            $totalpages
        );
    }
}

/**
 * Update the calendar entries for this digestforum activity.
 *
 * @param stdClass $digestforum the row from the database table digestforum.
 * @param int $cmid The coursemodule id
 * @return bool
 */
function digestforum_update_calendar($digestforum, $cmid) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/calendar/lib.php');

    $event = new stdClass();

    if (!empty($digestforum->duedate)) {
        $event->name = get_string('calendardue', 'digestforum', $digestforum->name);
        $event->description = format_module_intro('digestforum', $digestforum, $cmid, false);
        $event->format = FORMAT_HTML;
        $event->courseid = $digestforum->course;
        $event->modulename = 'digestforum';
        $event->instance = $digestforum->id;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = DFORUM_EVENT_TYPE_DUE;
        $event->timestart = $digestforum->duedate;
        $event->timesort = $digestforum->duedate;
        $event->visible = instance_is_visible('digestforum', $digestforum);
    }

    $event->id = $DB->get_field(
        'event',
        'id',
        ['modulename' => 'digestforum', 'instance' => $digestforum->id, 'eventtype' => DFORUM_EVENT_TYPE_DUE]
    );

    if ($event->id) {
        $calendarevent = calendar_event::load($event->id);
        if (!empty($digestforum->duedate)) {
            // Calendar event exists so update it.
            $calendarevent->update($event);
        } else {
            // Calendar event is no longer needed.
            $calendarevent->delete();
        }
    } else if (!empty($digestforum->duedate)) {
        // Event doesn't exist so create one.
        calendar_event::create($event);
    }

    return true;
}
