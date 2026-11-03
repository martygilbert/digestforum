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
 * Legacy data mapper factory.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\factories;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\local\data_mappers\legacy\author as author_data_mapper;
use mod_digestforum\local\data_mappers\legacy\discussion as discussion_data_mapper;
use mod_digestforum\local\data_mappers\legacy\digestforum as digestforum_data_mapper;
use mod_digestforum\local\data_mappers\legacy\post as post_data_mapper;
use mod_digestforum\local\entities\digestforum;

/**
 * Legacy data mapper factory.
 *
 * See:
 * https://designpatternsphp.readthedocs.io/en/latest/Creational/SimpleFactory/README.html
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_data_mapper {
    /**
     * Create a legacy digestforum data mapper.
     *
     * @return digestforum_data_mapper
     */
    public function get_digestforum_data_mapper(): digestforum_data_mapper {
        return new digestforum_data_mapper();
    }

    /**
     * Create a legacy discussion data mapper.
     *
     * @return discussion_data_mapper
     */
    public function get_discussion_data_mapper(): discussion_data_mapper {
        return new discussion_data_mapper();
    }

    /**
     * Create a legacy post data mapper.
     *
     * @return post_data_mapper
     */
    public function get_post_data_mapper(): post_data_mapper {
        return new post_data_mapper();
    }

    /**
     * Create a legacy author data mapper.
     *
     * @return author_data_mapper
     */
    public function get_author_data_mapper(): author_data_mapper {
        return new author_data_mapper();
    }

    /**
     * Get the corresponding entity based on the supplied value
     *
     * @param string $entity
     * @return author_data_mapper|discussion_data_mapper|digestforum_data_mapper|post_data_mapper
     */
    public function get_legacy_data_mapper_for_vault($entity) {
        switch($entity) {
            case 'digestforum':
                return $this->get_digestforum_data_mapper();
            case 'discussion':
                return $this->get_discussion_data_mapper();
            case 'post':
                return $this->get_post_data_mapper();
            case 'author':
                return $this->get_author_data_mapper();
        }
    }
}
