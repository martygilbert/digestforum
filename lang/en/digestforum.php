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
 * Strings for component 'digestforum', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['activityoverview'] = 'There are new digest forum posts';
$string['addanewdiscussion'] = 'Add a new discussion topic';
$string['addanewquestion'] = 'Add a new question';
$string['addanewtopic'] = 'Add a new topic';
$string['advancedsearch'] = 'Advanced search';
$string['alldigestforums'] = 'All digest forums';
$string['allowdiscussions'] = 'Can a {$a} post to this digest forum?';
$string['allowsallsubscribe'] = 'This digest forum allows everyone to choose whether to subscribe or not';
$string['allowsdiscussions'] = 'This digest forum allows each person to start one discussion topic.';
$string['allsubscribe'] = 'Subscribe to all digest forums';
$string['allunsubscribe'] = 'Unsubscribe from all digest forums';
$string['alreadyfirstpost'] = 'This is already the first post in the discussion';
$string['anyfile'] = 'Any file';
$string['areaattachment'] = 'Attachments';
$string['areapost'] = 'Messages';
$string['attachment'] = 'Attachment';
$string['attachment_help'] = 'You can optionally attach one or more files to a digest forum post. If you attach an image, it will be displayed after the message.';
$string['attachmentnopost'] = 'You cannot export attachments without a post id';
$string['attachments'] = 'Attachments';
$string['attachmentswordcount'] = 'Attachments and word count';
$string['blockafter'] = 'Post threshold for blocking';
$string['blockafter_help'] = 'This setting specifies the maximum number of posts which a user can post in the given time period. Users with the capability mod/digestforum:postwithoutthrottling are exempt from post limits.';
$string['blockperiod'] = 'Time period for blocking';
$string['blockperiod_help'] = 'Students can be blocked from posting more than a given number of posts in a given time period. Users with the capability mod/digestforum:postwithoutthrottling are exempt from post limits.';
$string['blockperioddisabled'] = 'Don\'t block';
$string['blogdigestforum'] = 'Standard digest forum displayed in a blog-like format';
$string['bynameondate'] = 'by {$a->name} - {$a->date}';
$string['cannotadd'] = 'Could not add the discussion for this digest forum';
$string['cannotadddiscussion'] = 'Adding discussions to this digest forum requires group membership.';
$string['cannotadddiscussionall'] = 'You do not have permission to add a new discussion topic for all participants.';
$string['cannotaddsubscriber'] = 'Could not add subscriber with id {$a} to this digest forum!';
$string['cannotaddteacherdigestforumto'] = 'Could not add converted teacher digest forum instance to section 0 in the course';
$string['cannotcreatediscussion'] = 'Could not create new discussion';
$string['cannotcreateinstanceforteacher'] = 'Could not create new course module instance for the teacher digest forum';
$string['cannotdeletepost'] = 'You can\'t delete this post!';
$string['cannoteditposts'] = 'You can\'t edit other people\'s posts!';
$string['cannotfinddiscussion'] = 'Could not find the discussion in this digest forum';
$string['cannotfindfirstpost'] = 'Could not find the first post in this digest forum';
$string['cannotfindorcreatedigestforum'] = 'Could not find or create a main news digest forum for the site';
$string['cannotfindparentpost'] = 'Could not find top parent of post {$a}';
$string['cannotmovefromsingledigestforum'] = 'Cannot move discussion from a simple single discussion digest forum';
$string['cannotmovenotvisible'] = 'Digest forum not visible';
$string['cannotmovetonotexist'] = 'You can\'t move to that digest forum - it doesn\'t exist!';
$string['cannotmovetonotfound'] = 'Target digest forum not found in this course.';
$string['cannotmovetosingledigestforum'] = 'Cannot move discussion to a simple single discussion digest forum';
$string['cannotpurgecachedrss'] = 'Could not purge the cached RSS feeds for the source and/or destination digest forum(s) - check your file permissionsdigest forums';
$string['cannotremovesubscriber'] = 'Could not remove subscriber with id {$a} from this digest forum!';
$string['cannotreply'] = 'You cannot reply to this post';
$string['cannotsplit'] = 'Discussions from this digest forum cannot be split';
$string['cannotsubscribe'] = 'Sorry, but you must be a group member to subscribe.';
$string['cannottrack'] = 'Could not stop tracking that digest forum';
$string['cannotunsubscribe'] = 'Could not unsubscribe you from that digest forum';
$string['cannotupdatepost'] = 'You can not update this post';
$string['cannotviewpostyet'] = 'You cannot read other students questions in this discussion yet because you haven\'t posted';
$string['cannotviewusersposts'] = 'There are no posts made by this user that you are able to view.';
$string['cleanreadtime'] = 'Mark old posts as read hour';
$string['clicktounsubscribe'] = 'You are subscribed to this discussion. Click to unsubscribe.';
$string['clicktosubscribe'] = 'You are not subscribed to this discussion. Click to subscribe.';
$string['completiondiscussions'] = 'Student must create discussions:';
$string['completiondiscussionsgroup'] = 'Require discussions';
$string['completiondiscussionshelp'] = 'requiring discussions to complete';
$string['completionposts'] = 'Student must post discussions or replies:';
$string['completionpostsgroup'] = 'Require posts';
$string['completionpostshelp'] = 'requiring discussions or replies to complete';
$string['completionreplies'] = 'Student must post replies:';
$string['completionrepliesgroup'] = 'Require replies';
$string['completionreplieshelp'] = 'requiring replies to complete';
$string['configcleanreadtime'] = 'The hour of the day to clean old posts from the \'read\' table.';
$string['configdigestforum_mailtime'] = 'People who choose to have emails sent to them in digest form will be emailed the digest daily. This setting controls which time of day the daily mail will be sent (the next cron that runs after this hour will send it).';
$string['configdisplaymode'] = 'The default display mode for discussions if one isn\'t set.';
$string['configenablerssfeeds'] = 'This switch will enable the possibility of RSS feeds for all digest forums.  You will still need to turn feeds on manually in the settings for each digest forum.';
$string['configenabletimedposts'] = 'Set to \'yes\' if you want to allow setting of display periods when posting a new digest forum discussion.';
$string['configlongpost'] = 'Any post over this length (in characters not including HTML) is considered long. Posts displayed on the site front page, social format course pages, or user profiles are shortened to a natural break somewhere between the digest forum_shortpost and digest forum_longpost values.';
$string['configmanydiscussions'] = 'Maximum number of discussions shown in a digest forum per page';
$string['configmaxattachments'] = 'Default maximum number of attachments allowed per post.';
$string['configmaxbytes'] = 'Default maximum size for all digest forum attachments on the site (subject to course limits and other local settings)';
$string['configoldpostdays'] = 'Number of days old any post is considered read.';
$string['configreplytouser'] = 'When a digest forum post is mailed out, should it contain the user\'s email address so that recipients can reply personally rather than via the digest forum? Even if set to \'Yes\' users can choose in their profile to keep their email address secret.';
$string['configrsstypedefault'] = 'If RSS feeds are enabled, sets the default activity type.';
$string['configrssarticlesdefault'] = 'If RSS feeds are enabled, sets the default number of articles (either discussions or posts).';
$string['configshortpost'] = 'Any post under this length (in characters not including HTML) is considered short (see below).';
$string['configtrackingtype'] = 'Default setting for read tracking.';
$string['configtrackreadposts'] = 'Set to \'yes\' if you want to track read/unread for each user.';
$string['configusermarksread'] = 'If \'yes\', the user must manually mark a post as read. If \'no\', when the post is viewed it is marked as read.';
$string['confirmsubscribediscussion'] = 'Do you really want to subscribe to discussion \'{$a->discussion}\' in digestforum \'{$a->digest forum}\'?';
$string['confirmunsubscribediscussion'] = 'Do you really want to unsubscribe from discussion \'{$a->discussion}\' in digest forum \'{$a->digestforum}\'?';
$string['confirmsubscribe'] = 'Do you really want to subscribe to digest forum \'{$a}\'?';
$string['confirmunsubscribe'] = 'Do you really want to unsubscribe from digest forum \'{$a}\'?';
$string['couldnotadd'] = 'Could not add your post due to an unknown error';
$string['couldnotdeletereplies'] = 'Sorry, that cannot be deleted as people have already responded to it';
$string['couldnotupdate'] = 'Could not update your post due to an unknown error';
$string['crontask'] = 'Digest forum mailings and maintenance jobs';
$string['delete'] = 'Delete';
$string['deleteddiscussion'] = 'The discussion topic has been deleted';
$string['deletedpost'] = 'The post has been deleted';
$string['deletedposts'] = 'Those posts have been deleted';
$string['deletesure'] = 'Are you sure you want to delete this post?';
$string['deletesureplural'] = 'Are you sure you want to delete this post and all replies? ({$a} posts)';
$string['digestmailheader'] = 'This is your daily digest of new posts to \'{$a->digestforumname}\' from {$a->sitename} on {$a->date}';
$string['digestmailpost'] = 'Change your digest forum digest preferences';
$string['digestmailpostlink'] = 'Change your digest forum digest preferences: {$a}';
$string['digestmailprefs'] = 'your user profile';
$string['digestmailsubject'] = '{$a->digestforumname} - {$a->date}';
$string['digestforum_mailtime'] = 'Hour to send digest emails';
$string['digestsentusers'] = 'Email digests successfully sent to {$a} users.';
$string['disallowsubscribe'] = 'Subscriptions not allowed';
$string['disallowsubscription'] = 'Subscription';
$string['disallowsubscription_help'] = 'This digest forum has been configured so that you cannot subscribe to discussions.';
$string['disallowsubscribeteacher'] = 'Subscriptions not allowed (except for teachers)';
$string['discussion'] = 'Discussion';
$string['discussionmoved'] = 'This discussion has been moved to \'{$a}\'.';
$string['discussionmovedpost'] = 'This discussion has been moved to <a href="{$a->discusshref}">here</a> in the digest forum <a href="{$a->digestforumhref}">{$a->digestforumname}</a>';
$string['discussionname'] = 'Discussion name';
$string['discussionnownotsubscribed'] = '{$a->name} will NOT be notified of new posts in \'{$a->discussion}\' of \'{$a->digestforum}\'';
$string['discussionnowsubscribed'] = '{$a->name} will be notified of new posts in \'{$a->discussion}\' of \'{$a->digestforum}\'';
$string['discussionpin'] = 'Pin';
$string['discussionpinned'] = 'Pinned';
$string['discussionpinned_help'] = 'Pinned discussions will appear at the top of a digest forum.';
$string['discussionsubscribestop'] = 'I don\'t want to be notified of new posts in this discussion';
$string['discussionsubscribestart'] = 'Send me notifications of new posts in this discussion';
$string['discussionsubscription'] = 'Discussion subscription';
$string['discussionsubscription_help'] = 'Subscribing to a discussion means you will receive notifications of new posts to that discussion.';
$string['discussions'] = 'Discussions';
$string['discussionsstartedby'] = 'Discussions started by {$a}';
$string['discussionsstartedbyrecent'] = 'Discussions recently started by {$a}';
$string['discussionsstartedbyuserincourse'] = 'Discussions started by {$a->fullname} in {$a->coursename}';
$string['discussionunpin'] = 'Unpin';
$string['discussthistopic'] = 'Discuss this topic';
$string['displayend'] = 'Display end';
$string['displayend_help'] = 'This setting specifies whether a digest forum post should be hidden after a certain date. Note that administrators can always view digest forum posts.';
$string['displaymode'] = 'Display mode';
$string['displayperiod'] = 'Display period';
$string['displaystart'] = 'Display start';
$string['displaystart_help'] = 'This setting specifies whether a digest forum post should be displayed from a certain date. Note that administrators can always view digest forum posts.';
$string['displaywordcount'] = 'Display word count';
$string['displaywordcount_help'] = 'This setting specifies whether the word count of each post should be displayed or not.';
$string['eachuserdigestforum'] = 'Each person posts one discussion';
$string['edit'] = 'Edit';
$string['editedby'] = 'Edited by {$a->name} - original submission {$a->date}';
$string['editedpostupdated'] = '{$a}\'s post was updated';
$string['editing'] = 'Editing';
$string['eventcoursesearched'] = 'Course searched';
$string['eventdiscussioncreated'] = 'Discussion created';
$string['eventdiscussionupdated'] = 'Discussion updated';
$string['eventdiscussiondeleted'] = 'Discussion deleted';
$string['eventdiscussionmoved'] = 'Discussion moved';
$string['eventdiscussionviewed'] = 'Discussion viewed';
$string['eventdiscussionsubscriptioncreated'] = 'Discussion subscription created';
$string['eventdiscussionsubscriptiondeleted'] = 'Discussion subscription deleted';
$string['eventdiscussionpinned'] = 'Discussion pinned';
$string['eventdiscussionunpinned'] = 'Discussion unpinned';
$string['eventuserreportviewed'] = 'User report viewed';
$string['eventpostcreated'] = 'Post created';
$string['eventpostdeleted'] = 'Post deleted';
$string['eventpostupdated'] = 'Post updated';
$string['eventreadtrackingdisabled'] = 'Read tracking disabled';
$string['eventreadtrackingenabled'] = 'Read tracking enabled';
$string['eventsubscribersviewed'] = 'Subscribers viewed';
$string['eventsubscriptioncreated'] = 'Subscription created';
$string['eventsubscriptiondeleted'] = 'Subscription deleted';
$string['emaildigestcompleteshort'] = 'Complete posts';
$string['emaildigestdefault'] = 'Default ({$a})';
$string['emaildigestoffshort'] = 'No digest';
$string['emaildigestsubjectsshort'] = 'Subjects only';
$string['emaildigesttype'] = 'Email digest options';
$string['emaildigesttype_help'] = 'The type of notification that you will receive for each digest forum.

* Default - follow the digest setting found in your user profile. If you update your profile, then that change will be reflected here too;
* No digest - you will receive one e-mail per digest forum post;
* Digest - complete posts - you will receive one digest e-mail per day containing the complete contents of each digest forum post;
* Digest - subjects only - you will receive one digest e-mail per day containing just the subject of each digest forum post.
';
$string['emaildigestupdated'] = 'The e-mail digest option was changed to \'{$a->maildigesttitle}\' for the digest forum \'{$a->digestforum}\'. {$a->maildigestdescription}';
$string['emaildigestupdated_default'] = 'Your default profile setting of \'{$a->maildigesttitle}\' was used for the digest forum \'{$a->digestforum}\'. {$a->maildigestdescription}.';
$string['emaildigest_0'] = 'You will receive one e-mail per digest forum post.';
$string['emaildigest_1'] = 'You will receive one digest e-mail per day containing the complete contents of each digest forum post.';
$string['emaildigest_2'] = 'You will receive one digest e-mail per day containing the subject of each digest forum post.';
$string['emptymessage'] = 'Something was wrong with your post. Perhaps you left it blank, or the attachment was too big. Your changes have NOT been saved.';
$string['erroremptymessage'] = 'Post message cannot be empty';
$string['erroremptysubject'] = 'Post subject cannot be empty.';
$string['errorenrolmentrequired'] = 'You must be enrolled in this course to access this content';
$string['errorwhiledelete'] = 'An error occurred while deleting record.';
$string['eventassessableuploaded'] = 'Some content has been posted.';
$string['everyonecanchoose'] = 'Everyone can choose to be subscribed';
$string['everyonecannowchoose'] = 'Everyone can now choose to be subscribed';
$string['everyoneisnowsubscribed'] = 'Everyone is now subscribed to this digest forum';
$string['everyoneissubscribed'] = 'Everyone is subscribed to this digest forum';
$string['existingsubscribers'] = 'Existing subscribers';
$string['exportdiscussion'] = 'Export whole discussion to portfolio';
$string['forcedreadtracking'] = 'Allow forced read tracking';
$string['forcedreadtracking_desc'] = 'Allows digest forums to be set to forced read tracking. Will result in decreased performance for some users, particularly on courses with many digest forums and posts. When off, any digest forums previously set to Forced are treated as optional.';
$string['forcesubscribed_help'] = 'This digest forum has been configured so that you cannot unsubscribe from discussions.';
$string['forcesubscribed'] = 'This digest forum forces everyone to be subscribed';
$string['digestforum'] = 'Digest forum';
$string['digestforum:addinstance'] = 'Add a new digest forum';
$string['digestforum:addnews'] = 'Add news';
$string['digestforum:addquestion'] = 'Add question';
$string['digestforum:allowforcesubscribe'] = 'Allow force subscribe';
$string['digestforumauthorhidden'] = 'Author (hidden)';
$string['digestforumblockingalmosttoomanyposts'] = 'You are approaching the posting threshold. You have posted {$a->numposts} times in the last {$a->blockperiod} and the limit is {$a->blockafter} posts.';
$string['digestforumbodyhidden'] = 'This post cannot be viewed by you, probably because you have not posted in the discussion, the maximum editing time hasn\'t passed yet, the discussion has not started or the discussion has expired.';
$string['digestforum:canposttomygroups'] = 'Can post to all groups you have access to';
$string['digestforum:createattachment'] = 'Create attachments';
$string['digestforum:deleteanypost'] = 'Delete any posts (anytime)';
$string['digestforum:deleteownpost'] = 'Delete own posts (within deadline)';
$string['digestforum:editanypost'] = 'Edit any post';
$string['digestforum:exportdiscussion'] = 'Export whole discussion';
$string['digestforum:exportownpost'] = 'Export own post';
$string['digestforum:exportpost'] = 'Export post';
$string['digestforumintro'] = 'Description';
$string['digestforum:managesubscriptions'] = 'Manage subscriptions';
$string['digestforum:movediscussions'] = 'Move discussions';
$string['digestforum:pindiscussions'] = 'Pin discussions';
$string['digestforum:postwithoutthrottling'] = 'Exempt from post threshold';
$string['digestforumname'] = 'Digest forum name';
$string['digestforumposts'] = 'Digest forum posts';
$string['digestforum:rate'] = 'Rate posts';
$string['digestforum:replynews'] = 'Reply to news';
$string['digestforum:replypost'] = 'Reply to posts';
$string['digestforums'] = 'Digest forums';
$string['digestforum:splitdiscussions'] = 'Split discussions';
$string['digestforum:startdiscussion'] = 'Start new discussions';
$string['digestforumsubjecthidden'] = 'Subject (hidden)';
$string['digestforumtracked'] = 'Unread posts are being tracked';
$string['digestforumtrackednot'] = 'Unread posts are not being tracked';
$string['digestforumtype'] = 'Digest forum type';
$string['digestforumtype_help'] = 'There are 5 digest forum types:

* A single simple discussion - A single discussion topic which everyone can reply to (cannot be used with separate groups)
* Each person posts one discussion - Each student can post exactly one new discussion topic, which everyone can then reply to
* Q and A digest forum - Students must first post their perspectives before viewing other students\' posts
* Standard digest forum displayed in a blog-like format - An open digest forum where anyone can start a new discussion at any time, and in which discussion topics are displayed on one page with "Discuss this topic" links
* Standard digest forum for general use - An open digest forum where anyone can start a new discussion at any time';
$string['digestforum:viewallratings'] = 'View all raw ratings given by individuals';
$string['digestforum:viewanyrating'] = 'View total ratings that anyone received';
$string['digestforum:viewdiscussion'] = 'View discussions';
$string['digestforum:viewhiddentimedposts'] = 'View hidden timed posts';
$string['digestforum:viewqandawithoutposting'] = 'Always see Q and A posts';
$string['digestforum:viewrating'] = 'View the total rating you received';
$string['digestforum:viewsubscribers'] = 'View subscribers';
$string['generaldigestforum'] = 'Standard digest forum for general use';
$string['generaldigestforums'] = 'General digest forums';
$string['hiddendigestforumpost'] = 'Hidden digest forum post';
$string['indigestforum'] = 'in {$a}';
$string['introblog'] = 'The posts in this digest forum were copied here automatically from blogs of users in this course because those blog entries are no longer available';
$string['intronews'] = 'General news and announcements';
$string['introsocial'] = 'An open digest forum for chatting about anything you want to';
$string['introteacher'] = 'A digest forum for teacher-only notes and discussion';
$string['invalidaccess'] = 'This page was not accessed correctly';
$string['invaliddiscussionid'] = 'Discussion ID was incorrect or no longer exists';
$string['invaliddigestsetting'] = 'An invalid mail digest setting was provided';
$string['invalidforcesubscribe'] = 'Invalid force subscription mode';
$string['invaliddigestforumid'] = 'Digest forum ID was incorrect';
$string['invalidparentpostid'] = 'Parent post ID was incorrect';
$string['invalidpostid'] = 'Invalid post ID - {$a}';
$string['lastpost'] = 'Last post';
$string['learningdigestforums'] = 'Learning digest forums';
$string['longpost'] = 'Long post';
$string['mailnow'] = 'Send digest forum post notifications with no editing-time delay';
$string['manydiscussions'] = 'Discussions per page';
$string['markalldread'] = 'Mark all posts in this discussion read.';
$string['markallread'] = 'Mark all posts in this digest forum read.';
$string['markread'] = 'Mark read';
$string['markreadbutton'] = 'Mark<br />read';
$string['markunread'] = 'Mark unread';
$string['markunreadbutton'] = 'Mark<br />unread';
$string['maxattachments'] = 'Maximum number of attachments';
$string['maxattachments_help'] = 'This setting specifies the maximum number of files that can be attached to a digest forum post.';
$string['maxattachmentsize'] = 'Maximum attachment size';
$string['maxattachmentsize_help'] = 'This setting specifies the largest size of file that can be attached to a digest forum post.';
$string['maxtimehaspassed'] = 'Sorry, but the maximum time for editing this post ({$a}) has passed!';
$string['message'] = 'Message';
$string['messageinboundattachmentdisallowed'] = 'Unable to post your reply, since it includes an attachment and the digest forum doesn\'t allow attachments.';
$string['messageinboundfilecountexceeded'] = 'Unable to post your reply, since it includes more than the maximum number of attachments allowed for the digest forum ({$a->digestforum->maxattachments}).';
$string['messageinboundfilesizeexceeded'] = 'Unable to post your reply, since the total attachment size ({$a->filesize}) is greater than the maximum size allowed for the digest forum ({$a->maxbytes}).';
$string['messageinbounddigestforumhidden'] = 'Unable to post your reply, since the digest forum is currently unavailable.';
$string['messageinboundnopostdigestforum'] = 'Unable to post your reply, since you do not have permission to post in the {$a->digestforum->name} digest forum.';
$string['messageinboundthresholdhit'] = 'Unable to post your reply.  You have exceeded the posting threshold set for this digest forum';
$string['messageprovider:digests'] = 'Subscribed digest forum digests';
$string['messageprovider:posts'] = 'Subscribed digest forum posts';
$string['missingsearchterms'] = 'The following search terms occur only in the HTML markup of this message:';
$string['modeflatnewestfirst'] = 'Display replies flat, with newest first';
$string['modeflatoldestfirst'] = 'Display replies flat, with oldest first';
$string['modenested'] = 'Display replies in nested form';
$string['modethreaded'] = 'Display replies in threaded form';
$string['modulename'] = 'Digest forum';
$string['modulename_help'] = 'The digest forum activity module enables participants to have asynchronous discussions i.e. discussions that take place over an extended period of time.

There are several digest forum types to choose from, such as a standard digest forum where anyone can start a new discussion at any time; a digest forum where each student can post exactly one discussion; or a question and answer digest forum where students must first post before being able to view other students\' posts. A teacher can allow files to be attached to digest forum posts. Attached images are displayed in the digest forum post.

Participants can subscribe to a digest forum to receive notifications of new digest forum posts. A teacher can set the subscription mode to optional, forced or auto, or prevent subscription completely. If required, students can be blocked from posting more than a given number of posts in a given time period; this can prevent individuals from dominating discussions.

Digest forum posts can be rated by teachers or students (peer evaluation). Ratings can be aggregated to form a final grade which is recorded in the gradebook.

Digest forums have many uses, such as

* A social space for students to get to know each other
* For course announcements (using a news digest forum with forced subscription)
* For discussing course content or reading materials
* For continuing online an issue raised previously in a face-to-face session
* For teacher-only discussions (using a hidden digest forum)
* A help centre where tutors and students can give advice
* A one-on-one support area for private student-teacher communications (using a digest forum with separate groups and with one student per group)
* For extension activities, for example ‘brain teasers’ for students to ponder and suggest solutions to';
$string['modulename_link'] = 'mod/digestforum/view';
$string['modulenameplural'] = 'Digest forums';
$string['more'] = 'more';
$string['movedmarker'] = '(Moved)';
$string['movethisdiscussionto'] = 'Move this discussion to ...';
$string['mustprovidediscussionorpost'] = 'You must provide either a discussion id or post id to export';
$string['myprofileownpost'] = 'My digest forum posts';
$string['myprofileowndis'] = 'My digest forum discussions';
$string['myprofileotherdis'] = 'Digest forum discussions';
$string['namenews'] = 'Announcements';
$string['namenews_help'] = 'The course announcements digest forum is a special digest forum for announcements and is automatically created when a course is created. A course can have only one announcements digest forum. Only teachers and administrators can post announcements. The "Latest announcements" block will display recent announcements.';
$string['namesocial'] = 'Social digest forum';
$string['nameteacher'] = 'Teacher digest forum';
$string['nextdiscussiona'] = 'Next discussion: {$a}';
$string['newdigestforumposts'] = 'New digest forum posts';
$string['noattachments'] = 'There are no attachments to this post';
$string['nodiscussions'] = 'There are no discussion topics yet in this digest forum';
$string['nodiscussionsstartedby'] = '{$a} has not started any discussions';
$string['nodiscussionsstartedbyyou'] = 'You haven\'t started any discussions yet';
$string['noguestpost'] = 'Sorry, guests are not allowed to post.';
$string['noguestsubscribe'] = 'Sorry, guests are not allowed to subscribe.';
$string['noguesttracking'] = 'Sorry, guests are not allowed to set tracking options.';
$string['nomorepostscontaining'] = 'No more posts containing \'{$a}\' were found';
$string['nonews'] = 'No news has been posted yet';
$string['noonecansubscribenow'] = 'Subscriptions are now disallowed';
$string['nopermissiontosubscribe'] = 'You do not have the permission to view digest forum subscribers';
$string['nopermissiontoview'] = 'You do not have permissions to view this post';
$string['nopostdigestforum'] = 'Sorry, you are not allowed to post to this digest forum';
$string['noposts'] = 'No posts';
$string['nopostsmadebyuser'] = '{$a} has made no posts';
$string['nopostsmadebyyou'] = 'You haven\'t made any posts';
$string['noquestions'] = 'There are no questions yet in this digest forum';
$string['nosubscribers'] = 'There are no subscribers yet for this digest forum';
$string['notsubscribed'] = 'Subscribe';
$string['notexists'] = 'Discussion no longer exists';
$string['nothingnew'] = 'Nothing new for {$a}';
$string['notingroup'] = 'Sorry, but you need to be part of a group to see this digest forum.';
$string['notinstalled'] = 'The digest forum module is not installed';
$string['notpartofdiscussion'] = 'This post is not part of a discussion!';
$string['notrackdigestforum'] = 'Don\'t track unread posts';
$string['noviewdiscussionspermission'] = 'You do not have the permission to view discussions in this digest forum';
$string['nowallsubscribed'] = 'All digest forums in {$a} are subscribed.';
$string['nowallunsubscribed'] = 'All digest forums in {$a} are not subscribed.';
$string['nownotsubscribed'] = '{$a->name} will NOT be notified of new posts in \'{$a->digestforum}\'';
$string['nownottracking'] = '{$a->name} is no longer tracking \'{$a->digestforum}\'.';
$string['nowsubscribed'] = '{$a->name} will be notified of new posts in \'{$a->digestforum}\'';
$string['nowtracking'] = '{$a->name} is now tracking \'{$a->digestforum}\'.';
$string['numposts'] = '{$a} posts';
$string['olderdiscussions'] = 'Older discussions';
$string['oldertopics'] = 'Older topics';
$string['oldpostdays'] = 'Read after days';
$string['overviewnumpostssince'] = '{$a} posts since last login';
$string['overviewnumunread'] = '{$a} total unread';
$string['page-mod-digestforum-x'] = 'Any digest forum module page';
$string['page-mod-digestforum-view'] = 'Digest forum module main page';
$string['page-mod-digestforum-discuss'] = 'Digest forum module discussion thread page';
$string['parent'] = 'Show parent';
$string['parentofthispost'] = 'Parent of this post';
$string['permalink'] = 'Permalink';
$string['posttomygroups'] = 'Post a copy to all groups';
$string['posttomygroups_help'] = 'Posts a copy of this message to all groups you have access to. Participants in groups you do not have access to will not see this post';
$string['prevdiscussiona'] = 'Previous discussion: {$a}';
$string['pluginadministration'] = 'Digest forum administration';
$string['pluginname'] = 'Digest forum';
$string['postadded'] = '<p>Your post was successfully added.</p> <p>You have {$a} to edit it if you want to make any changes.</p>';
$string['postaddedsuccess'] = 'Your post was successfully added.';
$string['postaddedtimeleft'] = 'You have {$a} to edit it if you want to make any changes.';
$string['postbymailsuccess'] = 'Congratulations, your digest forum post with subject "{$a->subject}" was successfully added. You can view it at {$a->discussionurl}.';
$string['postbymailsuccess_html'] = 'Congratulations, your <a href="{$a->discussionurl}">digest forum post</a> with subject "{$a->subject}" was successfully posted.';
$string['postbyuser'] = '{$a->post} by {$a->user}';
$string['postincontext'] = 'See this post in context';
$string['postmailinfolink'] = 'This is a copy of a message posted in {$a->coursename}.

To reply click on this link: {$a->replylink}';
$string['postmailnow'] = '<p>This post will be mailed out immediately to all digest forum subscribers.</p>';
$string['postmailsubject'] = '{$a->courseshortname}: {$a->subject}';
$string['postrating1'] = 'Mostly separate knowing';
$string['postrating2'] = 'Separate and connected';
$string['postrating3'] = 'Mostly connected knowing';
$string['posts'] = 'Posts';
$string['postsmadebyuser'] = 'Posts made by {$a}';
$string['postsmadebyuserincourse'] = 'Posts made by {$a->fullname} in {$a->coursename}';
$string['posttodigestforum'] = 'Post to digest forum';
$string['postupdated'] = 'Your post was updated';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['processingdigest'] = 'Processing email digest for user {$a}';
$string['processingpost'] = 'Processing post {$a}';
$string['prune'] = 'Split';
$string['prunedpost'] = 'A new discussion has been created from that post';
$string['pruneheading'] = 'Split the discussion and move this post to a new discussion';
$string['qandadigestforum'] = 'Q and A digest forum';
$string['qandanotify'] = 'This is a question and answer digest forum. In order to see other responses to these questions, you must first post your answer';
$string['re'] = 'Re:';
$string['readtherest'] = 'Read the rest of this topic';
$string['replies'] = 'Replies';
$string['repliesmany'] = '{$a} replies so far';
$string['repliesone'] = '{$a} reply so far';
$string['reply'] = 'Reply';
$string['replydigestforum'] = 'Reply to digest forum';
$string['replytopostbyemail'] = 'You can reply to this via email.';
$string['replytouser'] = 'Use email address in reply';
$string['reply_handler'] = 'Reply to digest forum posts via email';
$string['reply_handler_name'] = 'Reply to digest forum posts';
$string['resetdigestforums'] = 'Delete posts from';
$string['resetdigestforumsall'] = 'Delete all posts';
$string['resetdigests'] = 'Delete all per-user digest forum digest preferences';
$string['resetsubscriptions'] = 'Delete all digest forum subscriptions';
$string['resettrackprefs'] = 'Delete all digest forum tracking preferences';
$string['rsssubscriberssdiscussions'] = 'RSS feed of discussions';
$string['rsssubscriberssposts'] = 'RSS feed of posts';
$string['rssarticles'] = 'Number of RSS recent articles';
$string['rssarticles_help'] = 'This setting specifies the number of articles (either discussions or posts) to include in the RSS feed. Between 5 and 20 generally acceptable.';
$string['rsstype'] = 'RSS feed for this activity';
$string['rsstype_help'] = 'To enable the RSS feed for this activity, select either discussions or posts to be included in the feed.';
$string['rsstypedefault'] = 'RSS feed type';
$string['search'] = 'Search';
$string['search:post'] = 'Digest forum - posts';
$string['search:activity'] = 'Digest forum - activity information';
$string['searchdatefrom'] = 'Posts must be newer than this';
$string['searchdateto'] = 'Posts must be older than this';
$string['searchdigestforumintro'] = 'Please enter search terms into one or more of the following fields:';
$string['searchdigestforums'] = 'Search digest forums';
$string['searchfullwords'] = 'These words should appear as whole words';
$string['searchnotwords'] = 'These words should NOT be included';
$string['searcholderposts'] = 'Search older posts...';
$string['searchphrase'] = 'This exact phrase must appear in the post';
$string['searchresults'] = 'Search results';
$string['searchsubject'] = 'These words should be in the subject';
$string['searchuser'] = 'This name should match the author';
$string['searchuserid'] = 'The Moodle ID of the author';
$string['searchwhichdigestforums'] = 'Choose which digest forums to search';
$string['searchwords'] = 'These words can appear anywhere in the post';
$string['seeallposts'] = 'See all posts made by this user';
$string['shortpost'] = 'Short post';
$string['showsubscribers'] = 'Show/edit current subscribers';
$string['singledigestforum'] = 'A single simple discussion';
$string['smallmessage'] = '{$a->user} posted in {$a->digestforumname}';
$string['smallmessagedigest'] = 'Digest forum digest containing {$a} messages';
$string['startedby'] = 'Started by';
$string['subject'] = 'Subject';
$string['subscribe'] = 'Subscribe to this digest forum';
$string['subscribediscussion'] = 'Subscribe to this discussion';
$string['subscribeall'] = 'Subscribe everyone to this digest forum';
$string['subscribeenrolledonly'] = 'Sorry, only enrolled users are allowed to subscribe to digest forum post notifications.';
$string['subscribed'] = 'Subscribed';
$string['subscribenone'] = 'Unsubscribe everyone from this digest forum';
$string['subscribers'] = 'Subscribers';
$string['subscriberstowithcount'] = 'Subscribers to "{$a->name}" ({$a->count})';
$string['subscribestart'] = 'Send me notifications of new posts in this digest forum';
$string['subscribestop'] = 'I don\'t want to be notified of new posts in this digest forum';
$string['subscription'] = 'Subscription';
$string['subscription_help'] = 'If you are subscribed to a digest forum it means you will receive notification of new digest forum posts. Usually you can choose whether you wish to be subscribed, though sometimes subscription is forced so that everyone receives notifications.';
$string['subscriptionandtracking'] = 'Subscription and tracking';
$string['subscriptionmode'] = 'Subscription mode';
$string['subscriptionmode_help'] = 'When a participant is subscribed to a digest forum it means they will receive digest forum post notifications. There are 4 subscription mode options:

* Optional subscription - Participants can choose whether to be subscribed
* Forced subscription - Everyone is subscribed and cannot unsubscribe
* Auto subscription - Everyone is subscribed initially but can choose to unsubscribe at any time
* Subscription disabled - Subscriptions are not allowed

Note: Any subscription mode changes will only affect users who enrol in the course in the future, and not existing users.';
$string['subscriptionoptional'] = 'Optional subscription';
$string['subscriptionforced'] = 'Forced subscription';
$string['subscriptionauto'] = 'Auto subscription';
$string['subscriptiondisabled'] = 'Subscription disabled';
$string['subscriptions'] = 'Subscriptions';
$string['thisdigestforumisthrottled'] = 'This digest forum has a limit to the number of digest forum postings you can make in a given time period - this is currently set at {$a->blockafter} posting(s) in {$a->blockperiod}';
$string['timedhidden'] = 'Timed status: Hidden from students';
$string['timedposts'] = 'Timed posts';
$string['timedvisible'] = 'Timed status: Visible to all users';
$string['timestartenderror'] = 'Display end date cannot be earlier than the start date';
$string['trackdigestforum'] = 'Track unread posts';
$string['tracking'] = 'Track';
$string['trackingoff'] = 'Off';
$string['trackingon'] = 'Forced';
$string['trackingoptional'] = 'Optional';
$string['trackingtype'] = 'Read tracking';
$string['trackingtype_help'] = 'If enabled, participants can track read and unread posts in the digest forum and in discussions. There are three options:

* Optional - Participants can choose whether to turn tracking on or off via a link in the administration block. Digest forum tracking must also be enabled in the user\'s profile settings.
* Forced - Tracking is always on, regardless of user setting. Available depending on administrative setting.
* Off - Read and unread posts are not tracked.';
$string['unread'] = 'Unread';
$string['unreadposts'] = 'Unread posts';
$string['unreadpostsnumber'] = '{$a} unread posts';
$string['unreadpostsone'] = '1 unread post';
$string['unsubscribe'] = 'Unsubscribe from this digest forum';
$string['unsubscribelink'] = 'Unsubscribe from this digest forum: {$a}';
$string['unsubscribediscussion'] = 'Unsubscribe from this discussion';
$string['unsubscribediscussionlink'] = 'Unsubscribe from this discussion: {$a}';
$string['unsubscribeall'] = 'Unsubscribe from all digest forums';
$string['unsubscribeallconfirm'] = 'You are currently subscribed to {$a->digestforums} digest forums, and {$a->discussions} discussions. Do you really want to unsubscribe from all digest forums and discussions, and disable discussion auto-subscription?';
$string['unsubscribeallconfirmdigestforums'] = 'You are currently subscribed to {$a->digestforums} digest forums. Do you really want to unsubscribe from all digest forums and disable discussion auto-subscription?';
$string['unsubscribeallconfirmdiscussions'] = 'You are currently subscribed to {$a->discussions} discussions. Do you really want to unsubscribe from all discussions and disable discussion auto-subscription?';
$string['unsubscribealldone'] = 'All optional digest forum subscriptions were removed. You will still receive notifications from digest forums with forced subscription. To manage digest forum notifications go to Messaging in My Profile Settings.';
$string['unsubscribeallempty'] = 'You are not subscribed to any digest forums. To disable all notifications from this server go to Messaging in My Profile Settings.';
$string['unsubscribed'] = 'Unsubscribed';
$string['unsubscribeshort'] = 'Unsubscribe';
$string['usermarksread'] = 'Manual message read marking';
$string['viewalldiscussions'] = 'View all discussions';
$string['warnafter'] = 'Post threshold for warning';
$string['warnafter_help'] = 'Students can be warned as they approach the maximum number of posts allowed in a given period. This setting specifies after how many posts they are warned. Users with the capability mod/digestforum:postwithoutthrottling are exempt from post limits.';
$string['warnformorepost'] = 'Warning! There is more than one discussion in this digest forum - using the most recent';
$string['yournewquestion'] = 'Your new question';
$string['yournewtopic'] = 'Your new discussion topic';
$string['yourreply'] = 'Your reply';

// Deprecated since Moodle 3.0.
$string['subscribersto'] = 'Subscribers to "{$a->name}"';

// Deprecated since Moodle 3.1.
$string['postmailinfo'] = 'This is a copy of a message posted on the {$a} website.

To reply click on this link:';
