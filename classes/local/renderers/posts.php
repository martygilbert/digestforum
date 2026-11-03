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
 * Posts renderer.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\renderers;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\local\builders\exported_posts as exported_posts_builder;
use renderer_base;
use stdClass;

/**
 * Posts renderer class.
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class posts {
    /** @var renderer_base $renderer Renderer base */
    private $renderer;
    /** @var exported_posts_builder $exportedpostsbuilder Builder for building exported posts */
    private $exportedpostsbuilder;
    /** @var string $template The template to render */
    private $template;
    /** @var callable $postprocessfortemplate Function to process exported posts before template rendering */
    private $postprocessfortemplate;

    /**
     * Constructor.
     *
     * @param renderer_base $renderer Renderer base
     * @param exported_posts_builder $exportedpostsbuilder Builder for building exported posts
     * @param string $template The template to render
     * @param callable $postprocessfortemplate Function to process exported posts before template rendering
     */
    public function __construct(
        renderer_base $renderer,
        exported_posts_builder $exportedpostsbuilder,
        string $template,
        ?callable $postprocessfortemplate = null
    ) {
        $this->renderer = $renderer;
        $this->exportedpostsbuilder = $exportedpostsbuilder;
        $this->template = $template;
        $this->postprocessfortemplate = $postprocessfortemplate;
    }

    /**
     * Render the given posts for the digestforums and discussions.
     *
     * @param stdClass $user The user viewing the posts
     * @param digestforum_entity[] $digestforums A list of all digestforums for these posts
     * @param discussion_entity[] $discussions A list of all discussions for these posts
     * @param post_entity[] $posts The posts to render
     * @return string
     */
    public function render(
        stdClass $user,
        array $digestforums,
        array $discussions,
        array $posts
    ): string {
        // Format the digestforums and discussion to make them more easily accessed later.
        $digestforums = array_reduce($digestforums, function($carry, $digestforum) {
            $carry[$digestforum->get_id()] = $digestforum;
            return $carry;
        }, []);
        $discussions = array_reduce($discussions, function($carry, $discussion) {
            $carry[$discussion->get_id()] = $discussion;
            return $carry;
        }, []);

        $exportedposts = $this->exportedpostsbuilder->build(
            $user,
            $digestforums,
            $discussions,
            $posts
        );

        if ($this->postprocessfortemplate !== null) {
            // We've got some post processing to do!
            $exportedposts = ($this->postprocessfortemplate)($exportedposts, $digestforums, $discussions, $user);
        }

        return $this->renderer->render_from_template(
            $this->template,
            ['posts' => array_values($exportedposts)]
        );
    }
}
