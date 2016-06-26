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
 * @package    mod_digestdigestforum
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the backup steps that will be used by the backup_digestdigestforum_activity_task
 */

/**
 * Define the complete digestdigestforum structure for backup, with file and id annotations
 */
class backup_digestdigestforum_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated

        $digestdigestforum = new backup_nested_element('digestdigestforum', array('id'), array(
            'type', 'name', 'intro', 'introformat',
            'assessed', 'assesstimestart', 'assesstimefinish', 'scale',
            'maxbytes', 'maxattachments', 'forcesubscribe', 'trackingtype',
            'rsstype', 'rssarticles', 'timemodified', 'warnafter',
            'blockafter', 'blockperiod', 'completiondiscussions', 'completionreplies',
            'completionposts', 'displaywordcount'));

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

        $digestdigestforum->add_child($discussions);
        $discussions->add_child($discussion);

        $digestdigestforum->add_child($subscriptions);
        $subscriptions->add_child($subscription);

        $digestdigestforum->add_child($digests);
        $digests->add_child($digest);

        $digestdigestforum->add_child($readposts);
        $readposts->add_child($read);

        $digestdigestforum->add_child($trackedprefs);
        $trackedprefs->add_child($track);

        $discussion->add_child($posts);
        $posts->add_child($post);

        $post->add_child($ratings);
        $ratings->add_child($rating);

        $discussion->add_child($discussionsubs);
        $discussionsubs->add_child($discussionsub);

        // Define sources

        $digestdigestforum->set_source_table('digestdigestforum', array('id' => backup::VAR_ACTIVITYID));

        // All these source definitions only happen if we are including user info
        if ($userinfo) {
            $discussion->set_source_sql('
                SELECT *
                  FROM {digestdigestforum_discussions}
                 WHERE digestdigestforum = ?',
                array(backup::VAR_PARENTID));

            // Need posts ordered by id so parents are always before childs on restore
            $post->set_source_table('digestdigestforum_posts', array('discussion' => backup::VAR_PARENTID), 'id ASC');
            $discussionsub->set_source_table('digestdigestforum_discussion_subs', array('discussion' => backup::VAR_PARENTID));

            $subscription->set_source_table('digestdigestforum_subscriptions', array('digestdigestforum' => backup::VAR_PARENTID));
            $digest->set_source_table('digestdigestforum_digests', array('digestdigestforum' => backup::VAR_PARENTID));

            $read->set_source_table('digestdigestforum_read', array('digestdigestforumid' => backup::VAR_PARENTID));

            $track->set_source_table('digestdigestforum_track_prefs', array('digestdigestforumid' => backup::VAR_PARENTID));

            $rating->set_source_table('rating', array('contextid'  => backup::VAR_CONTEXTID,
                                                      'component'  => backup_helper::is_sqlparam('mod_digestdigestforum'),
                                                      'ratingarea' => backup_helper::is_sqlparam('post'),
                                                      'itemid'     => backup::VAR_PARENTID));
            $rating->set_source_alias('rating', 'value');
        }

        // Define id annotations

        $digestdigestforum->annotate_ids('scale', 'scale');

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

        $digestdigestforum->annotate_files('mod_digestdigestforum', 'intro', null); // This file area hasn't itemid

        $post->annotate_files('mod_digestdigestforum', 'post', 'id');
        $post->annotate_files('mod_digestdigestforum', 'attachment', 'id');

        // Return the root element (digestdigestforum), wrapped into standard activity structure
        return $this->prepare_activity_structure($digestdigestforum);
    }

}
