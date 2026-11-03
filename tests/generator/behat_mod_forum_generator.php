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
 * Behat data generator for mod_digestforum.
 *
 * @package   mod_digestforum
 * @category  test
 * @copyright 2021 Noel De Martin
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_digestforum_generator extends behat_generator_base {

    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'discussions' => [
                'singular' => 'discussion',
                'datagenerator' => 'discussion',
                'required' => ['digestforum'],
                'switchids' => ['digestforum' => 'digestforumid', 'user' => 'userid', 'group' => 'groupid'],
            ],
            'posts' => [
                'singular' => 'post',
                'datagenerator' => 'post',
                'required' => [],
                'switchids' => ['digestforum' => 'digestforumid', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * Get the digestforum id using an activity idnumber or name.
     *
     * @param string $idnumberorname The digestforum activity idnumber or name.
     * @return int The digestforum id
     */
    protected function get_digestforum_id(string $idnumberorname): int {
        return $this->get_cm_by_activity_name('digestforum', $idnumberorname)->instance;
    }

    /**
     * Gets the group id from it's idnumber. It allows using 'All participants' as idnumber.
     *
     * @throws Exception
     * @param string $idnumber
     * @return int
     */
    protected function get_group_id($idnumber): int {
        if ($idnumber === 'All participants') {
            return -1;
        }

        return parent::get_group_id($idnumber);
    }

    /**
     * Preprocess discussion data.
     *
     * @param array $data Raw data.
     * @return array Processed data.
     */
    protected function preprocess_discussion(array $data) {
        global $DB, $USER;

        $digestforum = $DB->get_record('digestforum', ['id' => $data['digestforumid']]);

        unset($data['course']);
        unset($data['digestforumid']);

        return array_merge([
            'course' => $digestforum->course,
            'digestforum' => $digestforum->id,
            'userid' => $USER->id,
        ], $data);
    }

    /**
     * Preprocess post data.
     *
     * @param array $data Raw data.
     * @return array Processed data.
     */
    protected function preprocess_post(array $data) {
        global $DB, $USER;

        // Get discussion from name.
        $discussionfilters = array_filter([
            'name' => $data['discussion'] ?? null,
            'digestforum' => $data['digestforumid'] ?? null,
        ]);

        if (!empty($discussionfilters)) {
            if (!$discussionid = $DB->get_field('digestforum_discussions', 'id', $discussionfilters)) {
                throw new Exception('The specified discussion with name "' . $data['name'] . '" could not be found.');
            }

            $data['discussion'] = $discussionid;

            unset($data['digestforumid']);
        }

        // Get discussion from parent.
        $parentfilters = array_filter([
            'subject' => $data['parentsubject'] ?? null,
        ]);

        if (!empty($parentfilters)) {
            if (isset($discussionid)) {
                $parentfilters['discussion'] = $discussionid;
            }

            if (!$parent = $DB->get_record('digestforum_posts', $parentfilters)) {
                $parentdescription = implode(' and ', array_filter([
                    isset($parentfilters['subject']) ? 'subject "' . $parentfilters['subject'] . '"' : null,
                ]));

                throw new Exception('The specified post with ' . $parentdescription . ' could not be found.');
            }

            $data['parent'] = $parent->id;
            $data['discussion'] = $parent->discussion;

            unset($data['parentsubject']);
        }

        // Return processed data.
        if (!isset($data['discussion'])) {
            throw new Exception('It was not possible to find a discussion to create a post, '.
                'please specify discussion or parentsubject.');
        }

        return array_merge(['userid' => $USER->id], $data);
    }
}
