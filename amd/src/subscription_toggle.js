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
 * Handle discussion subscription toggling on a discussion list in
 * the digestforum view.
 *
 * @module     mod_digestforum/subscription_toggle
 * @copyright  2019 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
        'jquery',
        'core/templates',
        'core/notification',
        'mod_digestforum/repository',
        'mod_digestforum/selectors',
        'core/pubsub',
        'mod_digestforum/digestforum_events',
    ], function(
        $,
        Templates,
        Notification,
        Repository,
        Selectors,
        PubSub,
        ForumEvents
    ) {

    /**
     * Register event listeners for the subscription toggle.
     *
     * @param {object} root The discussion list root element
     * @param {boolean} preventDefault Should the default action of the event be prevented
     * @param {function} callback Success callback
     */
    var registerEventListeners = function(root, preventDefault, callback) {
        root.on('click', Selectors.subscription.toggle, function(e) {
            var toggleElement = $(this);
            var digestforumId = toggleElement.data('digestforumid');
            var discussionId = toggleElement.data('discussionid');
            var subscriptionState = toggleElement.data('targetstate');

            Repository.setDiscussionSubscriptionState(digestforumId, discussionId, subscriptionState)
                .then(function(context) {
                    PubSub.publish(ForumEvents.SUBSCRIPTION_TOGGLED, {
                        discussionId: discussionId,
                        subscriptionState: subscriptionState
                    });
                    return callback(toggleElement, context);
                })
                .catch(Notification.exception);

            if (preventDefault) {
                e.preventDefault();
            }
        });
    };

    return {
        init: registerEventListeners
    };
});
