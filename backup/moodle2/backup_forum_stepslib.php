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
 * @package    mod_digestforum
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the backup steps that will be used by the backup_digestforum_activity_task
 */

/**
 * Define the complete digestforum structure for backup, with file and id annotations
 */
class backup_digestforum_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated

        $digestforum = new backup_nested_element('digestforum', array('id'), array(
            'type', 'name', 'intro', 'introformat',
            'assessed', 'assesstimestart', 'assesstimefinish', 'scale',
            'maxbytes', 'maxattachments', 'forcesubscribe', 'trackingtype',
            'rsstype', 'rssarticles', 'timemodified', 'warnafter',
            'blockafter', 'blockperiod', 'completiondiscussions', 'completionreplies',
            'completionposts', 'displaywordcount', 'lockdiscussionafter'));

        $discussions = new backup_nested_element('discussions');

        $discussion = new backup_nested_element('discussion', array('id'), array(
            'name', 'firstpost', 'userid', 'groupid',
            'assessed', 'timemodified', 'usermodified', 'timestart',
            'timeend', 'pinned'));

        $posts = new backup_nested_element('posts');

        $post = new backup_nested_element('post', array('id'), array(
            'parent', 'userid', 'created', 'modified',
            'mailed', 'subject', 'message', 'messageformat',
            'messagetrust', 'attachment', 'totalscore', 'mailnow'));

        $tags = new backup_nested_element('poststags');
        $tag = new backup_nested_element('tag', array('id'), array('itemid', 'rawname'));

        $ratings = new backup_nested_element('ratings');

        $rating = new backup_nested_element('rating', array('id'), array(
            'component', 'ratingarea', 'scaleid', 'value', 'userid', 'timecreated', 'timemodified'));

        $discussionsubs = new backup_nested_element('discussion_subs');

        $discussionsub = new backup_nested_element('discussion_sub', array('id'), array(
            'userid',
            'preference',
        ));

        $subscriptions = new backup_nested_element('subscriptions');

        $subscription = new backup_nested_element('subscription', array('id'), array(
            'userid'));

        $digests = new backup_nested_element('digests');

        $digest = new backup_nested_element('digest', array('id'), array(
            'userid', 'maildigest'));

        $readposts = new backup_nested_element('readposts');

        $read = new backup_nested_element('read', array('id'), array(
            'userid', 'discussionid', 'postid', 'firstread',
            'lastread'));

        $trackedprefs = new backup_nested_element('trackedprefs');

        $track = new backup_nested_element('track', array('id'), array(
            'userid'));

        // Build the tree

        $digestforum->add_child($discussions);
        $discussions->add_child($discussion);

        $digestforum->add_child($subscriptions);
        $subscriptions->add_child($subscription);

        $digestforum->add_child($digests);
        $digests->add_child($digest);

        $digestforum->add_child($readposts);
        $readposts->add_child($read);

        $digestforum->add_child($trackedprefs);
        $trackedprefs->add_child($track);

        $digestforum->add_child($tags);
        $tags->add_child($tag);

        $discussion->add_child($posts);
        $posts->add_child($post);

        $post->add_child($ratings);
        $ratings->add_child($rating);

        $discussion->add_child($discussionsubs);
        $discussionsubs->add_child($discussionsub);

        // Define sources

        $digestforum->set_source_table('digestforum', array('id' => backup::VAR_ACTIVITYID));

        // All these source definitions only happen if we are including user info
        if ($userinfo) {
            $discussion->set_source_sql('
                SELECT *
                  FROM {digestforum_discussions}
                 WHERE digestforum = ?',
                array(backup::VAR_PARENTID));

            // Need posts ordered by id so parents are always before childs on restore
            $post->set_source_table('digestforum_posts', array('discussion' => backup::VAR_PARENTID), 'id ASC');
            $discussionsub->set_source_table('digestforum_discussion_subs', array('discussion' => backup::VAR_PARENTID));

            $subscription->set_source_table('digestforum_subscriptions', array('digestforum' => backup::VAR_PARENTID));
            $digest->set_source_table('digestforum_digests', array('digestforum' => backup::VAR_PARENTID));

            $read->set_source_table('digestforum_read', array('digestforumid' => backup::VAR_PARENTID));

            $track->set_source_table('digestforum_track_prefs', array('digestforumid' => backup::VAR_PARENTID));

            $rating->set_source_table('rating', array('contextid'  => backup::VAR_CONTEXTID,
                                                      'component'  => backup_helper::is_sqlparam('mod_digestforum'),
                                                      'ratingarea' => backup_helper::is_sqlparam('post'),
                                                      'itemid'     => backup::VAR_PARENTID));
            $rating->set_source_alias('rating', 'value');

            if (core_tag_tag::is_enabled('mod_digestforum', 'digestforum_posts')) {
                // Backup all tags for all digestforum posts in this digestforum.
                $tag->set_source_sql('SELECT t.id, ti.itemid, t.rawname
                                        FROM {tag} t
                                        JOIN {tag_instance} ti ON ti.tagid = t.id
                                       WHERE ti.itemtype = ?
                                         AND ti.component = ?
                                         AND ti.contextid = ?', array(
                    backup_helper::is_sqlparam('digestforum_posts'),
                    backup_helper::is_sqlparam('mod_digestforum'),
                    backup::VAR_CONTEXTID));
            }
        }

        // Define id annotations

        $digestforum->annotate_ids('scale', 'scale');

        $discussion->annotate_ids('group', 'groupid');

        $post->annotate_ids('user', 'userid');

        $discussionsub->annotate_ids('user', 'userid');

        $rating->annotate_ids('scale', 'scaleid');

        $rating->annotate_ids('user', 'userid');

        $subscription->annotate_ids('user', 'userid');

        $digest->annotate_ids('user', 'userid');

        $read->annotate_ids('user', 'userid');

        $track->annotate_ids('user', 'userid');

        // Define file annotations

        $digestforum->annotate_files('mod_digestforum', 'intro', null); // This file area hasn't itemid

        $post->annotate_files('mod_digestforum', 'post', 'id');
        $post->annotate_files('mod_digestforum', 'attachment', 'id');

        // Return the root element (digestforum), wrapped into standard activity structure
        return $this->prepare_activity_structure($digestforum);
    }

}
