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
 * Renderer factory.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\factories;

use mod_digestforum\grades\digestforum_gradeitem;
use mod_digestforum\local\entities\discussion as discussion_entity;
use mod_digestforum\local\entities\digestforum as digestforum_entity;
use mod_digestforum\local\factories\vault as vault_factory;
use mod_digestforum\local\factories\legacy_data_mapper as legacy_data_mapper_factory;
use mod_digestforum\local\factories\entity as entity_factory;
use mod_digestforum\local\factories\exporter as exporter_factory;
use mod_digestforum\local\factories\manager as manager_factory;
use mod_digestforum\local\factories\builder as builder_factory;
use mod_digestforum\local\factories\url as url_factory;
use mod_digestforum\local\renderers\discussion as discussion_renderer;
use mod_digestforum\local\renderers\discussion_list as discussion_list_renderer;
use mod_digestforum\local\renderers\posts as posts_renderer;
use moodle_page;
use core\output\notification;

/**
 * Renderer factory.
 *
 * See:
 * https://designpatternsphp.readthedocs.io/en/latest/Creational/SimpleFactory/README.html
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer {
    /** @var legacy_data_mapper_factory $legacydatamapperfactory Legacy data mapper factory */
    private $legacydatamapperfactory;
    /** @var exporter_factory $exporterfactory Exporter factory */
    private $exporterfactory;
    /** @var vault_factory $vaultfactory Vault factory */
    private $vaultfactory;
    /** @var manager_factory $managerfactory Manager factory */
    private $managerfactory;
    /** @var entity_factory $entityfactory Entity factory */
    private $entityfactory;
    /** @var builder_factory $builderfactory Builder factory */
    private $builderfactory;
    /** @var url_factory $urlfactory URL factory */
    private $urlfactory;
    /** @var renderer_base $rendererbase Renderer base */
    private $rendererbase;
    /** @var moodle_page $page Moodle page */
    private $page;

    /**
     * Constructor.
     *
     * @param legacy_data_mapper_factory $legacydatamapperfactory Legacy data mapper factory
     * @param exporter_factory $exporterfactory Exporter factory
     * @param vault_factory $vaultfactory Vault factory
     * @param manager_factory $managerfactory Manager factory
     * @param entity_factory $entityfactory Entity factory
     * @param builder_factory $builderfactory Builder factory
     * @param url_factory $urlfactory URL factory
     * @param moodle_page $page Moodle page
     */
    public function __construct(
        legacy_data_mapper_factory $legacydatamapperfactory,
        exporter_factory $exporterfactory,
        vault_factory $vaultfactory,
        manager_factory $managerfactory,
        entity_factory $entityfactory,
        builder_factory $builderfactory,
        url_factory $urlfactory,
        moodle_page $page
    ) {
        $this->legacydatamapperfactory = $legacydatamapperfactory;
        $this->exporterfactory = $exporterfactory;
        $this->vaultfactory = $vaultfactory;
        $this->managerfactory = $managerfactory;
        $this->entityfactory = $entityfactory;
        $this->builderfactory = $builderfactory;
        $this->urlfactory = $urlfactory;
        $this->page = $page;
        $this->rendererbase = $page->get_renderer('mod_digestforum');
    }

    /**
     * Create a discussion renderer for the given digestforum and discussion.
     *
     * @param digestforum_entity $digestforum Forum the discussion belongs to
     * @param discussion_entity $discussion Discussion to render
     * @param int $displaymode How should the posts be formatted?
     * @return discussion_renderer
     */
    public function get_discussion_renderer(
        digestforum_entity $digestforum,
        discussion_entity $discussion,
        int $displaymode
    ): discussion_renderer {

        $capabilitymanager = $this->managerfactory->get_capability_manager($digestforum);
        $ratingmanager = $this->managerfactory->get_rating_manager();
        $rendererbase = $this->rendererbase;

        $baseurl = $this->urlfactory->get_discussion_view_url_from_discussion($discussion);
        $notifications = [];

        return new discussion_renderer(
            $digestforum,
            $discussion,
            $displaymode,
            $rendererbase,
            $this->get_single_discussion_posts_renderer($displaymode, false),
            $this->page,
            $this->legacydatamapperfactory,
            $this->exporterfactory,
            $this->vaultfactory,
            $this->urlfactory,
            $this->entityfactory,
            $capabilitymanager,
            $ratingmanager,
            $this->entityfactory->get_exported_posts_sorter(),
            $baseurl,
            $notifications,
            function ($discussion, $user, $digestforum) {
                $exportbuilder = $this->builderfactory->get_exported_discussion_builder();
                return $exportbuilder->build(
                    $user,
                    $digestforum,
                    $discussion
                );
            }
        );
    }

    /**
     * Create a posts renderer to render posts without defined parent/reply relationships.
     *
     * @return posts_renderer
     */
    public function get_posts_renderer(): posts_renderer {
        return new posts_renderer(
            $this->rendererbase,
            $this->builderfactory->get_exported_posts_builder(),
            'mod_digestforum/digestforum_discussion_posts'
        );
    }

    /**
     * Create a posts renderer to render a list of posts in a single discussion.
     *
     * @param int|null $displaymode How should the posts be formatted?
     * @param bool $readonly Should the posts include the actions to reply, delete, etc?
     * @return posts_renderer
     */
    public function get_single_discussion_posts_renderer(?int $displaymode = null, bool $readonly = false): posts_renderer {
        $exportedpostssorter = $this->entityfactory->get_exported_posts_sorter();

        switch ($displaymode) {
            case DFORUM_MODE_THREADED:
                $template = 'mod_digestforum/digestforum_discussion_threaded_posts';
                break;
            case DFORUM_MODE_NESTED:
                $template = 'mod_digestforum/digestforum_discussion_nested_posts';
                break;
            case DFORUM_MODE_NESTED_V2:
                $template = 'mod_digestforum/digestforum_discussion_nested_v2_posts';
                break;
            default:
                $template = 'mod_digestforum/digestforum_discussion_posts';
                break;
        }

        return new posts_renderer(
            $this->rendererbase,
            $this->builderfactory->get_exported_posts_builder(),
            $template,
            // Post process the exported posts for our template. This function will add the "replies"
            // and "hasreplies" properties to the exported posts. It will also sort them into the
            // reply tree structure if the display mode requires it.
            function ($exportedposts, $digestforums, $discussions) use ($displaymode, $readonly, $exportedpostssorter) {
                $digestforum = array_shift($digestforums);
                $seenfirstunread = false;
                $postcount = count($exportedposts);
                $discussionsbyid = array_reduce($discussions, function ($carry, $discussion) {
                    $carry[$discussion->get_id()] = $discussion;
                    return $carry;
                }, []);
                $exportedposts = array_map(
                    function ($exportedpost) use ($digestforum, $discussionsbyid, $readonly, $seenfirstunread, $displaymode) {
                        $discussion = $discussionsbyid[$exportedpost->discussionid] ?? null;
                        if ($digestforum->get_type() == 'single' && !$exportedpost->hasparent) {
                            // Remove the author from any posts that don't have a parent.
                            unset($exportedpost->author);
                            unset($exportedpost->html['authorsubheading']);
                        }

                        $exportedpost->firstpost = false;
                        $exportedpost->readonly = $readonly;
                        $exportedpost->hasreplycount = false;
                        $exportedpost->hasreplies = false;
                        $exportedpost->replies = [];
                        $exportedpost->discussionlocked = $discussion ? $discussion->is_locked() : null;

                        $exportedpost->isfirstunread = false;
                        if (!$seenfirstunread && $exportedpost->unread) {
                            $exportedpost->isfirstunread = true;
                            $seenfirstunread = true;
                        }

                        if ($displaymode === DFORUM_MODE_NESTED_V2) {
                            $exportedpost->showactionmenu = $exportedpost->capabilities['view'] ||
                                                            $exportedpost->capabilities['controlreadstatus'] ||
                                                            $exportedpost->capabilities['edit'] ||
                                                            $exportedpost->capabilities['split'] ||
                                                            $exportedpost->capabilities['delete'] ||
                                                            $exportedpost->capabilities['export'] ||
                                                            !empty($exportedpost->urls['viewparent']);
                        }

                        return $exportedpost;
                    },
                    $exportedposts
                );

                if (
                    $displaymode === DFORUM_MODE_NESTED ||
                    $displaymode === DFORUM_MODE_THREADED ||
                    $displaymode === DFORUM_MODE_NESTED_V2
                ) {
                    $sortedposts = $exportedpostssorter->sort_into_children($exportedposts);
                    $sortintoreplies = function ($nestedposts) use (&$sortintoreplies) {
                        return array_map(function ($postdata) use (&$sortintoreplies) {
                            [$post, $replies] = $postdata;
                            $totalreplycount = 0;

                            if (empty($replies)) {
                                $post->replies = [];
                                $post->hasreplies = false;
                            } else {
                                $sortedreplies = $sortintoreplies($replies);
                                // Set the parent author name on the replies. This is used for screen
                                // readers to help them identify the structure of the discussion.
                                $sortedreplies = array_map(function ($reply) use ($post) {
                                    if (isset($post->author)) {
                                        $reply->parentauthorname = $post->author->fullname;
                                    } else {
                                        // The only time the author won't be set is for a single discussion
                                        // digestforum. See above for where it gets unset.
                                        $reply->parentauthorname = get_string('firstpost', 'mod_digestforum');
                                    }
                                    return $reply;
                                }, $sortedreplies);

                                $totalreplycount = array_reduce($sortedreplies, function ($carry, $reply) {
                                    return $carry + 1 + $reply->totalreplycount;
                                }, $totalreplycount);

                                $post->replies = $sortedreplies;
                                $post->hasreplies = true;
                            }

                            $post->totalreplycount = $totalreplycount;

                            return $post;
                        }, $nestedposts);
                    };
                    // Set the "replies" property on the exported posts.
                    $exportedposts = $sortintoreplies($sortedposts);
                } else if ($displaymode === DFORUM_MODE_FLATNEWEST || $displaymode === DFORUM_MODE_FLATOLDEST) {
                    $exportedfirstpost = array_shift($exportedposts);
                    $exportedfirstpost->replies = $exportedposts;
                    $exportedfirstpost->hasreplies = true;
                    $exportedposts = [$exportedfirstpost];
                }

                if (!empty($exportedposts)) {
                    // Need to identify the first post so that we can use it in behat tests.
                    $exportedposts[0]->firstpost = true;
                    $exportedposts[0]->hasreplycount = true;
                    $exportedposts[0]->replycount = $postcount - 1;
                }

                return $exportedposts;
            }
        );
    }

    /**
     * Create a posts renderer to render posts in the digestforum search results.
     *
     * @param string[] $searchterms The search terms to be highlighted in the posts
     * @return posts_renderer
     */
    public function get_posts_search_results_renderer(array $searchterms): posts_renderer {
        $urlfactory = $this->urlfactory;

        return new posts_renderer(
            $this->rendererbase,
            $this->builderfactory->get_exported_posts_builder(),
            'mod_digestforum/digestforum_search_results',
            // Post process the exported posts to add the highlighting of the search terms to the post
            // and also the additional context links in the subject.
            function ($exportedposts, $digestforumsbyid, $discussionsbyid) use ($searchterms, $urlfactory) {
                $highlightwords = implode(' ', $searchterms);

                return array_map(
                    function ($exportedpost) use (
                        $digestforumsbyid,
                        $discussionsbyid,
                        $searchterms,
                        $highlightwords,
                        $urlfactory
                    ) {
                        $discussion = $discussionsbyid[$exportedpost->discussionid];
                        $digestforum = $digestforumsbyid[$discussion->get_digestforum_id()];

                        $viewdiscussionurl = $urlfactory->get_discussion_view_url_from_discussion($discussion);
                        $viewurl = $urlfactory->get_digestforum_view_url_from_digestforum($digestforum);
                        $exportedpost->urls['viewdigestforum'] = $viewurl->out(false);
                        $exportedpost->urls['viewdiscussion'] = $viewdiscussionurl->out(false);
                        $exportedpost->subject = highlight($highlightwords, $exportedpost->subject);
                        $exportedpost->digestforumname = format_string($digestforum->get_name(), true);
                        $exportedpost->discussionname = highlight($highlightwords, format_string($discussion->get_name(), true));
                        $exportedpost->showdiscussionname = $digestforum->get_type() != 'single';

                        // Identify search terms only found in HTML markup, and add a warning about them to
                        // the start of the message text. This logic was copied exactly as is from the previous
                        // implementation.
                        $missingterms = '';
                        $exportedpost->message = highlight(
                            $highlightwords,
                            $exportedpost->message,
                            0,
                            '<fgw9sdpq4>',
                            '</fgw9sdpq4>'
                        );

                        foreach ($searchterms as $searchterm) {
                            if (
                                preg_match("/$searchterm/i", $exportedpost->message) &&
                                !preg_match('/<fgw9sdpq4>' . $searchterm . '<\/fgw9sdpq4>/i', $exportedpost->message)
                            ) {
                                $missingterms .= " $searchterm";
                            }
                        }

                        $exportedpost->message = str_replace('<fgw9sdpq4>', '<span class="highlight">', $exportedpost->message);
                        $exportedpost->message = str_replace('</fgw9sdpq4>', '</span>', $exportedpost->message);

                        if ($missingterms) {
                            $strmissingsearchterms = get_string('missingsearchterms', 'digestforum');
                            $exportedpost->message = '<p class="highlight2">' . $strmissingsearchterms . ' '
                                . $missingterms . '</p>' . $exportedpost->message;
                        }

                        return $exportedpost;
                    },
                    $exportedposts
                );
            }
        );
    }

    /**
     * Create a posts renderer to render posts in mod/digestforum/user.php.
     *
     * @param bool $addlinkstocontext Should links to the course, digestforum, and discussion be included?
     * @return posts_renderer
     */
    public function get_user_digestforum_posts_report_renderer(bool $addlinkstocontext): posts_renderer {
        $urlfactory = $this->urlfactory;

        return new posts_renderer(
            $this->rendererbase,
            $this->builderfactory->get_exported_posts_builder(),
            'mod_digestforum/digestforum_posts_with_context_links',
            function ($exportedposts, $digestforumsbyid, $discussionsbyid) use ($urlfactory, $addlinkstocontext) {

                return array_map(function ($exportedpost) use (
                    $digestforumsbyid,
                    $discussionsbyid,
                    $addlinkstocontext,
                    $urlfactory,
                ) {
                    $discussion = $discussionsbyid[$exportedpost->discussionid];
                    $digestforum = $digestforumsbyid[$discussion->get_digestforum_id()];
                    $courserecord = $digestforum->get_course_record();

                    if ($addlinkstocontext) {
                        $viewdiscussionurl = $urlfactory->get_discussion_view_url_from_discussion($discussion);
                        $viewurl = $urlfactory->get_digestforum_view_url_from_digestforum($digestforum);
                        $exportedpost->urls['viewdigestforum'] = $viewurl->out(false);
                        $exportedpost->urls['viewdiscussion'] = $viewdiscussionurl->out(false);
                        $exportedpost->urls['viewcourse'] = $urlfactory->get_course_url_from_digestforum($digestforum)->out(false);
                    }

                    $exportedpost->digestforumname = format_string($digestforum->get_name(), true);
                    $exportedpost->discussionname = format_string($discussion->get_name(), true);
                    $exportedpost->coursename = format_string($courserecord->shortname, true);
                    $exportedpost->showdiscussionname = $digestforum->get_type() != 'single';

                    return $exportedpost;
                }, $exportedposts);
            }
        );
    }

    /**
     * Create a standard type discussion list renderer.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussions belong to
     * @return discussion_list_renderer
     */
    public function get_discussion_list_renderer(
        digestforum_entity $digestforum
    ): discussion_list_renderer {

        $capabilitymanager = $this->managerfactory->get_capability_manager($digestforum);
        $rendererbase = $this->rendererbase;
        $notifications = [];

        switch ($digestforum->get_type()) {
            case 'news':
                if (SITEID == $digestforum->get_course_id()) {
                    $template = 'mod_digestforum/frontpage_news_discussion_list';
                } else {
                    $template = 'mod_digestforum/news_discussion_list';
                }
                break;
            case 'qanda':
                $template = 'mod_digestforum/qanda_discussion_list';
                break;
            default:
                $template = 'mod_digestforum/discussion_list';
        }

        return new discussion_list_renderer(
            $digestforum,
            $rendererbase,
            $this->legacydatamapperfactory,
            $this->exporterfactory,
            $this->vaultfactory,
            $this->builderfactory,
            $capabilitymanager,
            $this->urlfactory,
            digestforum_gradeitem::load_from_digestforum_entity($digestforum),
            $template,
            $notifications,
            function ($discussions, $user, $digestforum) {

                $exporteddiscussionsummarybuilder = $this->builderfactory->get_exported_discussion_summaries_builder();
                return $exporteddiscussionsummarybuilder->build(
                    $user,
                    $digestforum,
                    $discussions
                );
            }
        );
    }

    /**
     * Create a discussion list renderer which shows more information about the first post.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussions belong to
     * @param string $template The template to use
     * @return discussion_list_renderer
     */
    private function get_detailed_discussion_list_renderer(
        digestforum_entity $digestforum,
        string $template
    ): discussion_list_renderer {

        $capabilitymanager = $this->managerfactory->get_capability_manager($digestforum);
        $rendererbase = $this->rendererbase;
        $notifications = [];

        return new discussion_list_renderer(
            $digestforum,
            $rendererbase,
            $this->legacydatamapperfactory,
            $this->exporterfactory,
            $this->vaultfactory,
            $this->builderfactory,
            $capabilitymanager,
            $this->urlfactory,
            digestforum_gradeitem::load_from_digestforum_entity($digestforum),
            $template,
            $notifications,
            function ($discussions, $user, $digestforum) use ($capabilitymanager) {
                $exportedpostsbuilder = $this->builderfactory->get_exported_posts_builder();
                $discussionentries = [];
                $postentries = [];
                foreach ($discussions as $discussion) {
                    $discussionentries[] = $discussion->get_discussion();
                    $discussionentriesids[] = $discussion->get_discussion()->get_id();
                    $postentries[] = $discussion->get_first_post();
                }

                $exportedposts['posts'] = $exportedpostsbuilder->build(
                    $user,
                    [$digestforum],
                    $discussionentries,
                    $postentries
                );

                $postvault = $this->vaultfactory->get_post_vault();
                $canseeanyprivatereply = $capabilitymanager->can_view_any_private_reply($user);
                $discussionrepliescount = $postvault->get_reply_count_for_discussion_ids(
                    $user,
                    $discussionentriesids,
                    $canseeanyprivatereply
                );
                $digestforumdatamapper = $this->legacydatamapperfactory->get_digestforum_data_mapper();
                $digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
                if (digestforum_tp_is_tracked($digestforumrecord, $user)) {
                    $discussionunreadscount = $postvault->get_unread_count_for_discussion_ids(
                        $user,
                        $discussionentriesids,
                        $canseeanyprivatereply
                    );
                } else {
                    $discussionunreadscount = [];
                }

                array_walk($exportedposts['posts'], function ($post) use ($discussionrepliescount, $discussionunreadscount) {
                    $post->discussionrepliescount = $discussionrepliescount[$post->discussionid] ?? 0;
                    $post->discussionunreadscount = $discussionunreadscount[$post->discussionid] ?? 0;
                    // This is not ideal, as languages define singular and plural forms differently.
                    $post->isreplyplural = $post->discussionrepliescount != 1 ? true : false;
                    $post->isunreadplural = $post->discussionunreadscount != 1 ? true : false;
                });

                $exportedposts['state']['hasdiscussions'] = $exportedposts['posts'] ? true : false;

                return $exportedposts;
            }
        );
    }

    /**
     * Create a blog type discussion list renderer.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussions belong to
     * @return discussion_list_renderer
     */
    public function get_blog_discussion_list_renderer(
        digestforum_entity $digestforum
    ): discussion_list_renderer {
        return $this->get_detailed_discussion_list_renderer($digestforum, 'mod_digestforum/blog_discussion_list');
    }

    /**
     * Create a discussion list renderer for the social course format.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussions belong to
     * @return discussion_list_renderer
     */
    public function get_social_discussion_list_renderer(
        digestforum_entity $digestforum
    ): discussion_list_renderer {
        return $this->get_detailed_discussion_list_renderer($digestforum, 'mod_digestforum/social_discussion_list');
    }

    /**
     * Create a discussion list renderer for the social course format.
     *
     * @param digestforum_entity $digestforum The digestforum that the discussions belong to
     * @return discussion_list_renderer
     */
    public function get_frontpage_news_discussion_list_renderer(
        digestforum_entity $digestforum
    ): discussion_list_renderer {
        return $this->get_detailed_discussion_list_renderer($digestforum, 'mod_digestforum/frontpage_social_discussion_list');
    }

    /**
     * Create a single type discussion list renderer.
     *
     * @param digestforum_entity $digestforum Forum the discussion belongs to
     * @param discussion_entity $discussion The discussion entity
     * @param bool $hasmultiplediscussions Whether the digestforum has multiple discussions (more than one)
     * @param int $displaymode How should the posts be formatted?
     * @return discussion_renderer
     */
    public function get_single_discussion_list_renderer(
        digestforum_entity $digestforum,
        discussion_entity $discussion,
        bool $hasmultiplediscussions,
        int $displaymode
    ): discussion_renderer {

        $capabilitymanager = $this->managerfactory->get_capability_manager($digestforum);
        $ratingmanager = $this->managerfactory->get_rating_manager();
        $rendererbase = $this->rendererbase;

        $cmid = $digestforum->get_course_module_record()->id;
        $baseurl = $this->urlfactory->get_digestforum_view_url_from_course_module_id($cmid);
        $notifications = [];

        if ($hasmultiplediscussions) {
            $notifications[] = (new notification(get_string('warnformorepost', 'digestforum')))
                ->set_show_closebutton(true);
        }

        return new discussion_renderer(
            $digestforum,
            $discussion,
            $displaymode,
            $rendererbase,
            $this->get_single_discussion_posts_renderer($displaymode, false),
            $this->page,
            $this->legacydatamapperfactory,
            $this->exporterfactory,
            $this->vaultfactory,
            $this->urlfactory,
            $this->entityfactory,
            $capabilitymanager,
            $ratingmanager,
            $this->entityfactory->get_exported_posts_sorter(),
            $baseurl,
            $notifications
        );
    }
}
